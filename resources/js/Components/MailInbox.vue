<script setup>
// The mail inbox, shared by every project. Reads from whichever mail catcher
// is already running on this machine (smtp4dev, Mailpit, MailHog — detected
// server-side) and renders the mail itself, so nobody has to use the
// catchers' own web UIs. Nexus's own Mailpit (downloaded and configured in
// Settings → Mail) is only a fallback; with nothing running, this view offers
// to start it once or points to Settings.
//
// New mail arrives through a ChildProcess relaying the catcher's live events
// (scripts/mail-watch.mjs); outside the desktop app it falls back to polling.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { postJson, getJson, deleteJson } from '../lib/http.js';
import { onChildProcessMessage } from '../lib/nativeEvents.js';

const props = defineProps({
    // Changes whenever a mail-related setting does (URL, pin, Mailpit mode),
    // which means the server in use may have changed: re-detect.
    settingsKey: { type: String, default: '' },
});

const emit = defineEmits(['unread', 'open-settings']);

const WATCH_CHANGED = '__NEXUS_MAIL_CHANGED__';

const phase = ref('init'); // init | none | starting | ready | error
const status = ref({ sources: [], active: null, projects: [], mailpit: { installed: false, mode: 'off' } });
const notice = ref(''); // a problem with the fallback actions, shown in the empty state
const loadError = ref('');
const wiringOpen = ref(false);
const connecting = ref(null); // project id being wired
const connectError = ref('');
const messages = ref([]);
const selectedId = ref(null);
const detail = ref(null);
const sourceText = ref(null);
const bodyTab = ref('html');

let pollTimer = null;
let refreshTimer = null;
let stopWatching = null;
// Bumped on every init() and on unmount. Each async step checks it after
// awaiting, so a startup loop from a previous init (or an unmounted
// component) stops instead of wiring up a second watcher and poller.
let generation = 0;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const active = computed(() => status.value.active);
// Only projects with a .env can be wired; the rest are listed but inert.
const wireable = computed(() => status.value.projects.filter((p) => p.hasEnv));
const wiredCount = computed(() => wireable.value.filter((p) => p.connected).length);
const unreadCount = computed(() => messages.value.filter((m) => !m.read).length);

function hostOf(url) {
    return (url ?? '').replace(/^https?:\/\//, '');
}

async function fetchStatus() {
    const { ok, data } = await postJson('/mail/status');
    if (ok && data) status.value = data;
    return ok;
}

async function init() {
    const run = ++generation;
    teardownLive();
    phase.value = 'init';
    notice.value = '';
    clearSelection();
    messages.value = [];

    const ok = await fetchStatus();
    if (run !== generation) return;
    if (!ok) { phase.value = 'error'; return; }

    if (active.value) return becomeReady(run);
    // "Start with Nexus" kicked off our Mailpit; wait for it to bind.
    if (status.value.mailpitStarting) return awaitMailpit(run);
    phase.value = 'none';
}

async function becomeReady(run) {
    phase.value = 'ready';
    await loadMessages();
    if (run !== generation) return;
    connectLive(run);
}

async function loadMessages() {
    const { ok, data } = await getJson('/mail/messages');
    if (ok) {
        messages.value = data?.messages ?? [];
        loadError.value = '';
    } else {
        loadError.value = data?.error ?? 'Couldn\'t load the inbox.';
    }
}

function clearSelection() {
    selectedId.value = null;
    detail.value = null;
    sourceText.value = null;
}

async function select(id) {
    selectedId.value = id;
    detail.value = null;
    sourceText.value = null;
    const { ok, data } = await getJson(`/mail/message/${encodeURIComponent(id)}`);
    // A quicker click on another message may have landed first.
    if (selectedId.value !== id) return;
    if (!ok) {
        detail.value = { error: data?.error ?? 'Couldn\'t load this message.' };
        return;
    }
    detail.value = data;
    bodyTab.value = data?.html ? 'html' : 'text';
    // Opening a message marks it read in the catcher; mirror that locally.
    const msg = messages.value.find((m) => m.id === id);
    if (msg) msg.read = true;
}

async function loadSource() {
    const id = selectedId.value;
    if (sourceText.value !== null || !id) return;
    const { ok, data } = await getJson(`/mail/message/${encodeURIComponent(id)}/raw`);
    if (selectedId.value !== id) return;
    sourceText.value = ok ? data?.raw ?? '' : '(unavailable)';
}

async function clearInbox() {
    if (!window.confirm(`Delete all messages in ${active.value?.label ?? 'the inbox'}?`)) return;
    await deleteJson('/mail/messages');
    clearSelection();
    loadMessages();
}

// --- Wiring projects -----------------------------------------------------

// Re-read on open: projects may have been added, or .env files edited by hand.
async function toggleWiring() {
    wiringOpen.value = !wiringOpen.value;
    connectError.value = '';
    if (wiringOpen.value) await fetchStatus();
}

async function connectProject(project) {
    connecting.value = project.id;
    connectError.value = '';
    const { ok, data } = await postJson(`/mail/connect/${project.id}`);
    if (!ok) connectError.value = `${project.name}: ${data?.error ?? 'could not update .env'}`;
    await fetchStatus();
    connecting.value = null;
}

// --- Nexus's Mailpit (the fallback) ---------------------------------------

// Mailpit binds in well under a second, so check tightly at first and back
// off; each check probes over the single-threaded PHP server.
const STARTUP_BACKOFF_MS = [200, 300, 500, 800, 1200, 2000];

// "Start Mailpit" in the empty state: run it once, for this session.
async function startMailpit() {
    const run = ++generation;
    notice.value = '';
    phase.value = 'starting';

    const { data } = await postJson('/mail/mailpit/start');
    if (run !== generation) return;

    if (data?.state === 'missing') { phase.value = 'none'; status.value.mailpit.installed = false; return; }
    if (data?.state === 'unavailable') {
        phase.value = 'none';
        notice.value = 'Nexus can only run Mailpit inside the desktop app.';
        return;
    }

    return awaitMailpit(run);
}

async function awaitMailpit(run) {
    phase.value = 'starting';
    for (const delay of STARTUP_BACKOFF_MS) {
        await wait(delay);
        if (run !== generation) return;
        await fetchStatus();
        if (run !== generation) return;
        if (active.value) return becomeReady(run);
    }

    phase.value = 'none';
    notice.value = 'Mailpit didn\'t start. Is something else using port 8025 or 1025?';
}

// --- Live updates ----------------------------------------------------------

async function connectLive(run) {
    const { data } = await postJson('/mail/watch');
    if (run !== generation) return;

    if (data?.live) {
        stopWatching = onChildProcessMessage('mail-watch', (line) => {
            if (String(line).includes(WATCH_CHANGED)) scheduleRefresh();
        });
    } else {
        startPolling();
    }
}

function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(loadMessages, 300);
}

// Fallback when there's no live relay (outside the desktop app). Each tick is
// a round-trip through the single-threaded PHP server, so don't spend them
// while nobody is looking: a backgrounded window polls nothing and catches up
// on focus.
function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') loadMessages();
    }, 8000);
    document.addEventListener('visibilitychange', pollOnReveal);
}

function pollOnReveal() {
    if (document.visibilityState === 'visible') loadMessages();
}

function teardownLive() {
    stopWatching?.();
    stopWatching = null;
    clearInterval(pollTimer);
    clearTimeout(refreshTimer);
    document.removeEventListener('visibilitychange', pollOnReveal);
    pollTimer = null;
}

// -------------------------------------------------------------------------

const bodyTabs = computed(() => {
    const tabs = [];
    if (detail.value?.html) tabs.push({ key: 'html', label: 'HTML' });
    tabs.push({ key: 'text', label: 'Text' });
    tabs.push({ key: 'source', label: 'Source' });
    return tabs;
});

function nameOf(address) {
    return address?.name || address?.address || 'unknown';
}

function when(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const sameDay = date.toDateString() === new Date().toDateString();
    return sameDay
        ? date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

// Opens Mailpit's homepage in the OS browser; the app window itself never
// navigates away or opens new windows.
function openMailpitSite() {
    postJson('/links/mailpit');
}

watch(() => props.settingsKey, init, { immediate: true });
watch(unreadCount, (count) => emit('unread', count), { immediate: true });
watch(bodyTab, (tab) => tab === 'source' && loadSource());
onBeforeUnmount(() => {
    generation++;
    teardownLive();
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Header -->
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
            <h2 class="mr-1 text-sm font-semibold">Mail</h2>

            <span class="flex items-center gap-1.5">
                <span
                    class="h-2 w-2 rounded-full"
                    :class="phase === 'ready' ? 'bg-emerald-500' : ['starting', 'downloading', 'init'].includes(phase) ? 'bg-amber-500 animate-pulse' : 'bg-neutral-400'"
                ></span>
                <template v-if="active">
                    {{ active.label }}
                    <span class="font-mono text-neutral-400">{{ hostOf(active.url) }}</span>
                    <button
                        v-if="status.sources.length > 1"
                        type="button"
                        class="text-neutral-400 underline hover:text-neutral-600 dark:hover:text-neutral-200"
                        :title="`${status.sources.length} mail servers are running — choose which to read in Settings`"
                        @click="emit('open-settings')"
                    >
                        +{{ status.sources.length - 1 }} more
                    </button>
                </template>
                <span v-else class="text-neutral-400">No mail server</span>
            </span>

            <div class="ml-auto flex items-center gap-1">
                <div v-if="phase === 'ready' && status.projects.length" class="relative">
                    <button
                        type="button"
                        class="flex items-center gap-1 rounded border border-neutral-300 px-2 py-1 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        :class="wiredCount < wireable.length ? 'text-amber-600 dark:text-amber-400' : ''"
                        :title="`Which projects send their mail to ${active?.label} (SMTP port ${active?.smtpPort})`"
                        @click="toggleWiring"
                    >
                        {{ wiredCount }} of {{ wireable.length }} {{ wireable.length === 1 ? 'project' : 'projects' }} wired
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <template v-if="wiringOpen">
                        <div class="fixed inset-0 z-40" @click="wiringOpen = false"></div>
                        <div class="absolute right-0 top-full z-50 mt-1 w-64 rounded-md border border-neutral-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-800">
                            <div
                                v-for="project in status.projects"
                                :key="project.id"
                                class="flex items-center gap-2 px-3 py-1.5"
                            >
                                <span class="truncate text-neutral-800 dark:text-neutral-100">{{ project.name }}</span>
                                <span v-if="!project.hasEnv" class="ml-auto shrink-0 text-neutral-400">no .env</span>
                                <span v-else-if="project.connected" class="ml-auto shrink-0 text-emerald-600 dark:text-emerald-400">✓ wired</span>
                                <button
                                    v-else
                                    type="button"
                                    class="ml-auto shrink-0 rounded bg-emerald-600 px-2 py-0.5 text-white hover:bg-emerald-500 disabled:opacity-50"
                                    :disabled="connecting !== null"
                                    :title="`Point this project's MAIL_* settings at ${active?.smtpHost}:${active?.smtpPort}`"
                                    @click="connectProject(project)"
                                >
                                    {{ connecting === project.id ? 'Connecting…' : 'Connect' }}
                                </button>
                            </div>
                            <p v-if="connectError" class="px-3 py-1.5 text-red-500">{{ connectError }}</p>
                        </div>
                    </template>
                </div>

                <button
                    v-if="phase === 'ready'"
                    type="button"
                    class="rounded border border-neutral-300 px-2 py-1 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    @click="loadMessages"
                >
                    Refresh
                </button>
                <button
                    v-if="phase === 'ready' && messages.length"
                    type="button"
                    class="rounded border border-red-400 px-2 py-1 text-red-600 hover:bg-red-50 dark:border-red-500/60 dark:text-red-400 dark:hover:bg-red-950/40"
                    @click="clearInbox"
                >
                    Clear
                </button>

            </div>
        </div>

        <!-- States -->
        <div v-if="phase === 'init' || phase === 'starting'" class="flex flex-1 items-center justify-center text-sm text-neutral-400">
            {{ phase === 'starting' ? 'Starting Mailpit…' : 'Looking for a mail server…' }}
        </div>

        <div v-else-if="phase === 'none'" class="flex flex-1 items-center justify-center p-6">
            <div class="max-w-sm text-center text-sm text-neutral-500">
                <p class="mb-1 font-medium text-neutral-700 dark:text-neutral-200">No mail server is running</p>
                <p class="mb-4 text-xs">
                    Nexus reads mail from the server you already use: smtp4dev (port 5000), Mailpit or
                    MailHog (port 8025). Start yours, or use
                    <a class="text-sky-600 underline dark:text-sky-400" href="https://mailpit.axllent.org" @click.prevent="openMailpitSite">Mailpit</a>
                    through Nexus.
                </p>

                <div class="flex justify-center gap-2">
                    <button
                        v-if="status.mailpit.installed"
                        type="button"
                        class="rounded bg-emerald-600 px-3 py-1.5 text-white hover:bg-emerald-500"
                        @click="startMailpit"
                    >
                        Start Mailpit
                    </button>
                    <button
                        type="button"
                        class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="init"
                    >
                        Check again
                    </button>
                </div>

                <button type="button" class="mt-3 text-xs text-sky-600 underline dark:text-sky-400" @click="emit('open-settings')">
                    {{ status.mailpit.installed ? 'Mailpit settings' : 'Set up Mailpit in Settings' }}
                </button>

                <p v-if="notice" class="mt-3 text-xs text-red-500">{{ notice }}</p>
            </div>
        </div>

        <div v-else-if="phase === 'error'" class="flex flex-1 items-center justify-center p-6 text-center text-sm text-red-500">
            Couldn't check for mail servers. <button type="button" class="ml-1 underline" @click="init">Retry</button>
        </div>

        <!-- Inbox -->
        <div v-else class="flex min-h-0 flex-1">
            <!-- List -->
            <div class="w-72 shrink-0 overflow-auto border-r border-neutral-200 dark:border-neutral-800">
                <div v-if="loadError" class="p-4 text-center text-xs text-red-500">
                    {{ loadError }}
                    <button type="button" class="ml-1 underline" @click="init">Check again</button>
                </div>
                <div v-else-if="!messages.length" class="p-4 text-center text-xs text-neutral-400">
                    No mail yet. Anything sent to {{ active?.smtpHost }}:{{ active?.smtpPort }} shows up here.
                </div>
                <button
                    v-for="msg in messages"
                    :key="msg.id"
                    type="button"
                    class="block w-full border-b border-neutral-100 px-3 py-2 text-left dark:border-neutral-900"
                    :class="selectedId === msg.id ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-900'"
                    @click="select(msg.id)"
                >
                    <div class="flex items-center gap-1.5">
                        <span v-if="!msg.read" class="h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500"></span>
                        <span class="truncate text-xs font-medium text-neutral-800 dark:text-neutral-100">{{ msg.subject || '(no subject)' }}</span>
                        <span class="ml-auto shrink-0 text-[10px] text-neutral-400">{{ when(msg.date) }}</span>
                    </div>
                    <div class="mt-0.5 truncate text-[11px] text-neutral-500">{{ nameOf(msg.from) }}</div>
                    <div v-if="msg.snippet" class="truncate text-[11px] text-neutral-400">{{ msg.snippet }}</div>
                </button>
            </div>

            <!-- Detail -->
            <div class="flex min-w-0 flex-1 flex-col">
                <div v-if="!selectedId" class="flex flex-1 items-center justify-center text-sm text-neutral-400">Select a message.</div>
                <div v-else-if="!detail" class="flex flex-1 items-center justify-center text-sm text-neutral-400">Loading…</div>
                <div v-else-if="detail.error" class="flex flex-1 items-center justify-center text-sm text-red-500">{{ detail.error }}</div>
                <template v-else>
                    <div class="border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
                        <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ detail.subject || '(no subject)' }}</div>
                        <div class="mt-0.5 text-xs text-neutral-500">
                            {{ detail.from?.address }} →
                            {{ (detail.to || []).map((t) => t.address).join(', ') }}
                            <template v-if="detail.cc?.length"> · cc {{ detail.cc.map((t) => t.address).join(', ') }}</template>
                        </div>
                        <div class="mt-1 flex gap-1">
                            <button
                                v-for="t in bodyTabs"
                                :key="t.key"
                                type="button"
                                class="rounded px-2 py-0.5 text-xs"
                                :class="bodyTab === t.key ? 'bg-neutral-200 text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : 'text-neutral-500'"
                                @click="bodyTab = t.key"
                            >
                                {{ t.label }}
                            </button>
                            <span
                                v-if="detail.attachments?.length"
                                class="ml-2 self-center truncate text-[11px] text-neutral-400"
                                :title="detail.attachments.map((a) => a.name).join(', ')"
                            >
                                📎 {{ detail.attachments.map((a) => a.name).join(', ') }}
                            </span>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto">
                        <iframe
                            v-if="bodyTab === 'html'"
                            :srcdoc="detail.html"
                            sandbox=""
                            class="h-full w-full border-0 bg-white"
                        ></iframe>
                        <pre v-else-if="bodyTab === 'text'" class="whitespace-pre-wrap break-words p-3 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ detail.text || '(no text part)' }}</pre>
                        <pre v-else class="whitespace-pre-wrap break-words p-3 font-mono text-xs text-neutral-500">{{ sourceText ?? 'Loading…' }}</pre>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
