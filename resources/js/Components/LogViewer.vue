<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, triggerRef, watch } from 'vue';
import { createLogAccumulator, inTimeWindow, levelStyle } from '../lib/logParser.js';
import { postJson } from '../lib/http.js';
import { nativeAvailable, onChildProcessMessage } from '../lib/nativeEvents.js';

const props = defineProps({
    activeProject: { type: Object, default: null },
    settings: { type: Object, default: () => ({ notifyErrors: true }) },
    // {from, to}: only show entries logged in this span (a tinker run's).
    focus: { type: Object, default: null },
});

const emit = defineEmits(['clear-focus']);

const SEVERE = ['emergency', 'alert', 'critical', 'error'];

/** How much history the parser keeps. */
const MAX_ENTRIES = 2000;
/** How much of it reaches the DOM — rows are unvirtualised, so this is a cap. */
const MAX_ROWS = 500;

// `tail -n 200` replays what is already in the file before it starts following,
// and this component is recreated every time the Logs tab is opened — so
// without a gate, opening the tab on a log that already holds errors fires a
// notification for history the developer has already seen.
//
// The replay is written in one burst, so a short gap with no output marks its
// end. The ceiling is the backstop: on a project erroring in a tight loop the
// gap never comes, and notifications have to arm anyway.
const BACKFILL_IDLE_MS = 400;
const BACKFILL_CEILING_MS = 3000;

const search = ref('');
const activeLevels = ref(new Set());
const expanded = ref(new Set());
const status = ref('idle'); // idle | connecting | live | missing | error
const logPath = ref('');
const errorMessage = ref('');
const containerEl = ref(null);
// Follow the tail only while the reader is already at the bottom, so scrolling
// back to read something doesn't get yanked away by the next line.
const pinned = ref(true);
let unsubscribe = null;
let lastNotifyAt = 0;
// Notifications stay disarmed until the tail's backfill has drained.
let notifyArmed = false;
let idleHandle = null;
let ceilingHandle = null;
// Bumped per start(); a slower response for a project we've since switched
// away from must not overwrite the current status.
let startToken = 0;

// The parser owns the entry list and mutates it in place; `entries` is a
// shallowRef onto that same array, refreshed with triggerRef after each flush.
// Deep reactivity here would mean Vue proxying every entry, detail line and
// stack frame that streams past — all cost, no benefit, since nothing mutates
// an entry after the parser is done with it.
const accumulator = createLogAccumulator({ maxEntries: MAX_ENTRIES });
const entries = shallowRef(accumulator.entries);

const presentLevels = computed(() => [...new Set(entries.value.map((e) => e.level))]);

const filtered = computed(() =>
    entries.value.filter((entry) => {
        if (!inTimeWindow(entry, props.focus)) return false;
        if (activeLevels.value.size && !activeLevels.value.has(entry.level)) return false;
        const q = search.value.trim().toLowerCase();
        if (q) {
            const hay = `${entry.message} ${entry.raw} ${entry.details.join(' ')}`.toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    }),
);

// Collapse consecutive identical entries into one row carrying a repeat count —
// the classic "same exception firing in a loop" case.
const rows = computed(() => {
    const out = [];
    for (const entry of filtered.value) {
        const sig = `${entry.level}|${entry.message}|${entry.details.join('\n')}`;
        const prev = out[out.length - 1];
        if (prev && prev.sig === sig) {
            prev.count++;
        } else {
            // Keyed by the entry's own id, not its position: the parser drops
            // old entries off the front, and a positional key would slide
            // expansion state onto whatever row inherited the index.
            out.push({ entry, sig, count: 1, key: entry.id });
        }
    }
    return out;
});

// Rows render unvirtualised, so a long session would otherwise put thousands of
// nodes in the DOM and make every update a full-tree diff. Show the newest.
const visibleRows = computed(() =>
    rows.value.length > MAX_ROWS ? rows.value.slice(-MAX_ROWS) : rows.value,
);

const hiddenRows = computed(() => rows.value.length - visibleRows.value.length);

const statusMeta = computed(() => ({
    idle: { dot: 'bg-neutral-400', label: 'Idle' },
    connecting: { dot: 'bg-amber-500 animate-pulse', label: 'Connecting…' },
    live: { dot: 'bg-emerald-500', label: 'Live' },
    missing: { dot: 'bg-neutral-400', label: 'No log file' },
    error: { dot: 'bg-red-500', label: 'Unavailable' },
}[status.value]));

// Parsing happens per chunk (cheap, each line is touched once); re-rendering is
// batched to one frame. A burst of tail output would otherwise re-run the
// filter/dedupe pipeline and re-render for every message the child process
// emits, which on a chatty log is many times per frame.
let flushHandle = null;
let batch = [];

function armNotifications() {
    notifyArmed = true;
    clearBackfillTimers();
}

function clearBackfillTimers() {
    clearTimeout(idleHandle);
    clearTimeout(ceilingHandle);
    idleHandle = null;
    ceilingHandle = null;
}

/**
 * Called on start and again for every chunk that lands while still disarmed:
 * each one pushes the idle deadline out, so the whole backfill burst — however
 * many chunks it is split across — passes without notifying. A log that is
 * empty or quiet never calls back in, hence arming from start() too.
 */
function delayNotifications() {
    if (notifyArmed) return;
    clearTimeout(idleHandle);
    idleHandle = setTimeout(armNotifications, BACKFILL_IDLE_MS);
}

function appendChunk(chunk) {
    delayNotifications();

    const fresh = accumulator.push(chunk);
    if (fresh.length) batch.push(...fresh);
    if (status.value !== 'live') status.value = 'live';

    if (flushHandle !== null) return;

    flushHandle = requestAnimationFrame(async () => {
        flushHandle = null;
        const opened = batch;
        batch = [];

        triggerRef(entries);

        const severe = opened.find((e) => SEVERE.includes(e.level));
        if (severe) maybeNotify(severe);

        if (!pinned.value) return;
        await nextTick();
        if (containerEl.value) containerEl.value.scrollTop = containerEl.value.scrollHeight;
    });
}

function onScroll() {
    const el = containerEl.value;
    if (!el) return;
    // A small slack so sub-pixel scroll heights don't unpin at the bottom.
    pinned.value = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
}

// The tail's own output can't confirm a successful start — a healthy tail on a
// quiet log is silent — so the start response is what settles the status.
async function start() {
    if (!props.activeProject) {
        startToken++;
        status.value = 'idle';
        return;
    }

    // Before the request, not after: the tail is spawned server-side and its
    // first chunk can reach us ahead of the response.
    notifyArmed = false;
    clearBackfillTimers();
    delayNotifications();
    ceilingHandle = setTimeout(armNotifications, BACKFILL_CEILING_MS);

    status.value = 'connecting';
    errorMessage.value = '';

    const token = ++startToken;
    const { ok, data } = await postJson('/logs/start');
    if (token !== startToken) return;
    logPath.value = data?.path ?? '';

    if (ok) {
        status.value = 'live';
        return;
    }

    status.value = data?.status === 'missing' ? 'missing' : 'error';
    errorMessage.value = data?.error
        ?? 'Could not start the log tail. Live logs run inside the desktop app.';
}

async function stop() {
    await postJson('/logs/stop');
}

function clear() {
    accumulator.reset();
    batch = [];
    triggerRef(entries);
    expanded.value = new Set();
    pinned.value = true;
}

// Truncate laravel.log itself, then the view: the tail keeps following the
// (now empty) file, so whatever happens next starts from a clean slate.
async function clearFile() {
    if (!window.confirm(`Empty ${logPath.value || 'laravel.log'}? This deletes its contents.`)) return;
    const { ok, data } = await postJson('/logs/clear');
    if (ok) clear();
    else window.alert(data?.error ?? 'Couldn\'t empty the log file.');
}

function toggleLevel(level) {
    const next = new Set(activeLevels.value);
    next.has(level) ? next.delete(level) : next.add(level);
    activeLevels.value = next;
}

function toggleEntry(key) {
    const next = new Set(expanded.value);
    next.has(key) ? next.delete(key) : next.add(key);
    expanded.value = next;
}

// A3: hand a stack frame's file:line to the desktop shell → editor URL scheme.
function openInEditor(frame) {
    postJson('/editor/open', { file: frame.file, line: frame.line });
}

// Last two path segments, whichever separator the host OS uses. Splitting on
// "/" alone left Windows frames ("C:\app\Foo.php") rendering as the full path.
const shortPath = (file) => file.split(/[\\/]/).slice(-2).join('/');

// A6: notify on newly-streamed severe entries (throttled so a burst is one ping).
function maybeNotify(entry) {
    if (!notifyArmed || !props.settings?.notifyErrors || !nativeAvailable()) return;
    const now = Date.now();
    if (now - lastNotifyAt < 5000) return;
    lastNotifyAt = now;
    postJson('/notify', {
        title: `${entry.originalLevel || 'ERROR'} — ${props.activeProject?.name ?? 'App'}`,
        body: (entry.message || '').slice(0, 300),
    });
}

// Severe-entry notification and auto-scroll both used to be watchers over
// `entries.length` / `rows.length`. Both now happen inside the batched flush in
// appendChunk(), which already knows exactly which entries are new — no
// diffing, and no watcher firing once per streamed line.

// Restart the tail when the active project changes.
watch(
    () => props.activeProject?.id,
    async () => {
        clear();
        await start();
    },
);

onMounted(() => {
    unsubscribe = onChildProcessMessage('tail', appendChunk);
    start();
});

onBeforeUnmount(() => {
    startToken++;
    unsubscribe?.();
    if (flushHandle !== null) cancelAnimationFrame(flushHandle);
    clearBackfillTimers();
    stop();
});
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Controls -->
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
            <span class="flex items-center gap-1.5 text-xs">
                <span class="h-2 w-2 rounded-full" :class="statusMeta.dot"></span>
                {{ statusMeta.label }}
            </span>

            <input
                v-model="search"
                type="text"
                placeholder="Filter logs…"
                class="min-w-40 flex-1 rounded border border-neutral-300 bg-transparent px-2 py-1 text-xs dark:border-neutral-700"
            />

            <div class="flex flex-wrap gap-1">
                <button
                    v-for="level in presentLevels"
                    :key="level"
                    type="button"
                    class="rounded px-1.5 py-0.5 text-[10px] uppercase"
                    :class="activeLevels.has(level)
                        ? levelStyle(level).text + ' ring-1 ring-current'
                        : 'text-neutral-400'"
                    @click="toggleLevel(level)"
                >
                    {{ level }}
                </button>
            </div>

            <button
                v-if="focus"
                type="button"
                class="flex items-center gap-1 rounded bg-sky-100 px-2 py-0.5 text-[11px] text-sky-800 hover:bg-sky-200 dark:bg-sky-900/50 dark:text-sky-200"
                :title="`${focus.from} – ${focus.to}`"
                @click="emit('clear-focus')"
            >
                Only the run's entries ✕
            </button>

            <span class="text-[10px] text-neutral-400">{{ rows.length }} entries</span>

            <button
                type="button"
                class="rounded px-2 py-0.5 text-xs text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                title="Clear this view (the file is untouched)"
                @click="clear"
            >
                Clear
            </button>
            <button
                v-if="status === 'live'"
                type="button"
                class="rounded px-2 py-0.5 text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                :title="`Empty ${logPath}`"
                @click="clearFile"
            >
                Empty log file
            </button>
        </div>

        <!-- Stream -->
        <div
            ref="containerEl"
            class="min-h-0 flex-1 overflow-auto bg-neutral-50 p-2 font-mono text-xs dark:bg-neutral-950"
            @scroll.passive="onScroll"
        >
            <div v-if="!rows.length" class="p-4 text-center text-neutral-400">
                <template v-if="!activeProject">
                    Select a project to stream its logs.
                </template>

                <template v-else-if="status === 'missing'">
                    <p class="text-neutral-500 dark:text-neutral-400">No log file found.</p>
                    <p class="mt-1 break-all text-[11px]">{{ logPath }}</p>
                    <p class="mt-1 text-[11px]">
                        Laravel creates it on the first log write.
                    </p>
                    <button
                        type="button"
                        class="mt-3 rounded border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="start"
                    >
                        Retry
                    </button>
                </template>

                <template v-else-if="status === 'error'">
                    <p class="text-red-500">{{ errorMessage }}</p>
                    <button
                        type="button"
                        class="mt-3 rounded border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="start"
                    >
                        Retry
                    </button>
                </template>

                <template v-else-if="status === 'connecting'">
                    Connecting…
                </template>

                <template v-else>
                    Tailing {{ logPath }} — no output yet.
                </template>
            </div>

            <p v-if="hiddenRows" class="pb-2 text-center text-[10px] text-neutral-400">
                {{ hiddenRows }} older {{ hiddenRows === 1 ? 'entry' : 'entries' }} hidden — filter to narrow the view
            </p>

            <div
                v-for="row in visibleRows"
                :key="row.key"
                class="border-b border-neutral-100 py-1 last:border-0 dark:border-neutral-900"
            >
                <div
                    class="flex cursor-pointer items-start gap-2"
                    @click="row.entry.details.length && toggleEntry(row.key)"
                >
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :class="levelStyle(row.entry.level).dot"></span>
                    <span v-if="row.entry.timestamp" class="shrink-0 text-neutral-400">{{ row.entry.timestamp }}</span>
                    <span class="shrink-0 font-semibold" :class="levelStyle(row.entry.level).text">
                        {{ (row.entry.originalLevel || row.entry.level).toUpperCase() }}
                    </span>
                    <span class="break-words text-neutral-800 dark:text-neutral-200">{{ row.entry.message }}</span>
                    <span
                        v-if="row.count > 1"
                        class="shrink-0 rounded bg-neutral-200 px-1.5 text-[10px] font-semibold text-neutral-600 dark:bg-neutral-700 dark:text-neutral-200"
                        title="Repeated consecutively"
                    >×{{ row.count }}</span>
                    <span v-if="row.entry.details.length" class="ml-auto shrink-0 text-neutral-400">
                        {{ expanded.has(row.key) ? '▾' : '▸' }}
                    </span>
                </div>

                <div v-if="row.entry.details.length && expanded.has(row.key)" class="mt-1 pl-4">
                    <!-- A3: jump-to-source shortcuts for each stack frame -->
                    <div v-if="row.entry.stack.length" class="mb-1 flex flex-wrap gap-1">
                        <button
                            v-for="(frame, fi) in row.entry.stack"
                            :key="fi"
                            type="button"
                            class="rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] text-sky-700 hover:bg-sky-100 dark:bg-neutral-800 dark:text-sky-400 dark:hover:bg-sky-950"
                            :title="`Open ${frame.file}:${frame.line}`"
                            @click.stop="openInEditor(frame)"
                        >
                            {{ shortPath(frame.file) }}:{{ frame.line }}
                        </button>
                    </div>

                    <pre class="whitespace-pre-wrap break-words text-neutral-500">{{ row.entry.details.join('\n') }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
