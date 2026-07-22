<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { buildParsedLogs, levelStyle } from '../lib/logParser.js';
import { postJson } from '../lib/http.js';
import { nativeAvailable, onChildProcessMessage } from '../lib/nativeEvents.js';

const props = defineProps({
    activeProject: { type: Object, default: null },
    settings: { type: Object, default: () => ({ notifyErrors: true }) },
});

const SEVERE = ['emergency', 'alert', 'critical', 'error'];

const raw = ref('');
const search = ref('');
const activeLevels = ref(new Set());
const expanded = ref(new Set());
const status = ref('idle'); // idle | connecting | live | error
const containerEl = ref(null);
let unsubscribe = null;
let lastNotifyAt = 0;

const entries = computed(() => buildParsedLogs(raw.value));
const presentLevels = computed(() => [...new Set(entries.value.map((e) => e.level))]);

const filtered = computed(() =>
    entries.value.filter((entry) => {
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
// the classic "same exception firing in a loop" case. Keyed by first-seen index
// so expansion state stays stable as new lines stream in.
const rows = computed(() => {
    const out = [];
    filtered.value.forEach((entry, i) => {
        const sig = `${entry.level}|${entry.message}|${entry.details.join('\n')}`;
        const prev = out[out.length - 1];
        if (prev && prev.sig === sig) {
            prev.count++;
        } else {
            out.push({ entry, sig, count: 1, key: i });
        }
    });
    return out;
});

const statusMeta = computed(() => ({
    idle: { dot: 'bg-neutral-400', label: 'Idle' },
    connecting: { dot: 'bg-amber-500 animate-pulse', label: 'Connecting…' },
    live: { dot: 'bg-emerald-500', label: 'Live' },
    error: { dot: 'bg-red-500', label: 'Unavailable' },
}[status.value]));

function appendChunk(chunk) {
    raw.value += chunk;
    if (raw.value.length > 500000) raw.value = raw.value.slice(-500000);
    if (status.value !== 'live') status.value = 'live';
}

async function start() {
    if (!props.activeProject) {
        status.value = 'idle';
        return;
    }
    status.value = 'connecting';
    const { ok } = await postJson('/logs/start');
    if (!ok || !nativeAvailable()) {
        status.value = 'error';
    }
}

async function stop() {
    await postJson('/logs/stop');
}

function clear() {
    raw.value = '';
    expanded.value = new Set();
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

const shortPath = (file) => file.split('/').slice(-2).join('/');

// A6: notify on newly-streamed severe entries (throttled so a burst is one ping).
function maybeNotify(entry) {
    if (!props.settings?.notifyErrors || !nativeAvailable()) return;
    const now = Date.now();
    if (now - lastNotifyAt < 5000) return;
    lastNotifyAt = now;
    postJson('/notify', {
        title: `${entry.originalLevel || 'ERROR'} — ${props.activeProject?.name ?? 'App'}`,
        body: (entry.message || '').slice(0, 300),
    });
}

watch(
    () => entries.value.length,
    (len, prev) => {
        // Only look at genuinely new entries; ignore resets/trims (len <= prev).
        if (len > (prev ?? 0)) {
            const fresh = entries.value.slice(prev ?? 0);
            const severe = fresh.find((e) => SEVERE.includes(e.level));
            if (severe) maybeNotify(severe);
        }
    },
);

// Auto-scroll to the newest entry.
watch(
    () => rows.value.length,
    async () => {
        await nextTick();
        if (containerEl.value) containerEl.value.scrollTop = containerEl.value.scrollHeight;
    },
);

// Restart the tail when the active project changes.
watch(
    () => props.activeProject?.id,
    async () => {
        raw.value = '';
        await start();
    },
);

onMounted(() => {
    unsubscribe = onChildProcessMessage('tail', appendChunk);
    start();
});

onBeforeUnmount(() => {
    unsubscribe?.();
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

            <span class="text-[10px] text-neutral-400">{{ rows.length }} entries</span>

            <button
                type="button"
                class="rounded px-2 py-0.5 text-xs text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                @click="clear"
            >
                Clear
            </button>
        </div>

        <!-- Stream -->
        <div ref="containerEl" class="min-h-0 flex-1 overflow-auto bg-neutral-50 p-2 font-mono text-xs dark:bg-neutral-950">
            <div v-if="!rows.length" class="p-4 text-center text-neutral-400">
                <template v-if="status === 'error'">
                    Live logs run inside the desktop app.
                </template>
                <template v-else-if="!activeProject">
                    Select a project to stream its logs.
                </template>
                <template v-else>
                    Waiting for log output…
                </template>
            </div>

            <div
                v-for="row in rows"
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
