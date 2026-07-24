<script setup>
// F3/F4 — Mailpit inbox. Manages the lifecycle handshake (detect → start →
// poll), lists messages, and renders HTML/Text/Source. New mail arrives via a
// direct websocket to Mailpit (kept off the single-threaded PHP server) with a
// polling fallback.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { postJson, getJson, deleteJson } from '../lib/http.js';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const phase = ref('init'); // init | starting | ready | missing | error
const state = ref({ running: false, source: 'down', apiUrl: '', mail: null });
const messages = ref([]);
const selectedId = ref(null);
const detail = ref(null);
const sourceText = ref(null);
const bodyTab = ref('html');

let ws = null;
let pollTimer = null;
let refreshTimer = null;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const connected = computed(() => state.value.mail?.connected ?? false);
const hasEnv = computed(() => state.value.mail?.exists ?? false);

function applyState(data) {
    if (data) state.value = { running: !!data.running, source: data.source, apiUrl: data.apiUrl, mail: data.mail ?? null };
}

async function init() {
    teardownLive();
    phase.value = 'init';
    messages.value = [];
    detail.value = null;
    selectedId.value = null;

    const { data } = await postJson('/mail/status');
    applyState(data);

    if (state.value.running) return becomeReady();
    if (state.value.source === 'missing') { phase.value = 'missing'; return; }
    return ensureRunning();
}

async function ensureRunning() {
    phase.value = 'starting';
    applyState((await postJson('/mail/start')).data);

    for (let i = 0; i < 8 && !state.value.running; i++) {
        await wait(1000);
        applyState((await postJson('/mail/status')).data);
    }

    if (state.value.running) return becomeReady();
    phase.value = state.value.source === 'missing' ? 'missing' : 'error';
}

function becomeReady() {
    phase.value = 'ready';
    loadMessages();
    connectLive();
}

async function loadMessages() {
    const { ok, data } = await getJson('/mail/messages');
    if (ok) messages.value = data?.messages ?? [];
}

async function select(id) {
    selectedId.value = id;
    detail.value = null;
    sourceText.value = null;
    const { ok, data } = await getJson(`/mail/message/${id}`);
    if (ok) {
        detail.value = data;
        bodyTab.value = data?.HTML ? 'html' : 'text';
    }
}

async function loadSource() {
    if (sourceText.value !== null || !selectedId.value) return;
    const { ok, data } = await getJson(`/mail/message/${selectedId.value}/raw`);
    sourceText.value = ok ? data?.raw ?? '' : '(unavailable)';
}

async function connectEnv() {
    await postJson('/mail/connect');
    applyState((await postJson('/mail/status')).data);
}

async function clearInbox() {
    if (!window.confirm('Delete all messages from the inbox?')) return;
    await deleteJson('/mail/messages');
    detail.value = null;
    selectedId.value = null;
    loadMessages();
}

// --- Live updates -------------------------------------------------------

function connectLive() {
    const wsUrl = state.value.apiUrl.replace(/^http/, 'ws') + '/api/events';
    try {
        ws = new WebSocket(wsUrl);
        ws.onmessage = scheduleRefresh;
        ws.onerror = startPolling;
        ws.onclose = startPolling;
    } catch {
        startPolling();
    }
}

function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(loadMessages, 300);
}

function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(loadMessages, 4000);
}

function teardownLive() {
    try { ws?.close(); } catch { /* ignore */ }
    ws = null;
    clearInterval(pollTimer);
    clearTimeout(refreshTimer);
    pollTimer = null;
}

const bodyTabs = computed(() => {
    const tabs = [];
    if (detail.value?.HTML) tabs.push({ key: 'html', label: 'HTML' });
    tabs.push({ key: 'text', label: 'Text' });
    tabs.push({ key: 'source', label: 'Source' });
    return tabs;
});

function fromLabel(msg) {
    const f = msg.From ?? {};
    return f.Name || f.Address || 'unknown';
}

watch(() => props.activeProject?.id, init, { immediate: true });
watch(bodyTab, (tab) => tab === 'source' && loadSource());
onBeforeUnmount(teardownLive);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Header -->
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
            <span class="flex items-center gap-1.5">
                <span
                    class="h-2 w-2 rounded-full"
                    :class="phase === 'ready' ? 'bg-emerald-500' : phase === 'starting' ? 'bg-amber-500 animate-pulse' : 'bg-neutral-400'"
                ></span>
                Mailpit
                <span v-if="state.source === 'detected'" class="text-neutral-400">(existing)</span>
            </span>

            <span v-if="hasEnv && !connected" class="text-amber-500">· active project not wired to Mailpit</span>

            <div class="ml-auto flex items-center gap-1">
                <button
                    v-if="phase === 'ready' && hasEnv && !connected"
                    type="button"
                    class="rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-500"
                    @click="connectEnv"
                >
                    Connect this app
                </button>
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
        <div v-if="!activeProject" class="flex flex-1 items-center justify-center text-sm text-neutral-400">
            Select a project to view its mail.
        </div>
        <div v-else-if="phase === 'starting' || phase === 'init'" class="flex flex-1 items-center justify-center text-sm text-neutral-400">
            {{ phase === 'starting' ? 'Starting Mailpit…' : 'Checking Mailpit…' }}
        </div>
        <div v-else-if="phase === 'missing'" class="flex flex-1 items-center justify-center p-6">
            <div class="max-w-md text-center text-sm text-neutral-500">
                <p class="mb-2 font-medium text-neutral-700 dark:text-neutral-200">Mailpit isn't running and no binary was found.</p>
                <p>Install <a class="text-sky-600 underline dark:text-sky-400" href="https://mailpit.axllent.org" target="_blank" rel="noopener">Mailpit</a>
                (Herd bundles it), or set <code class="rounded bg-neutral-200 px-1 dark:bg-neutral-800">NEXUS_MAILPIT_PATH</code>,
                then Refresh.</p>
                <button type="button" class="mt-3 rounded bg-neutral-800 px-3 py-1.5 text-white dark:bg-neutral-700" @click="init">Retry</button>
            </div>
        </div>
        <div v-else-if="phase === 'error'" class="flex flex-1 items-center justify-center p-6 text-center text-sm text-red-500">
            Couldn't reach Mailpit. <button type="button" class="ml-1 underline" @click="init">Retry</button>
        </div>

        <!-- Inbox -->
        <div v-else class="flex min-h-0 flex-1">
            <!-- List -->
            <div class="w-72 shrink-0 overflow-auto border-r border-neutral-200 dark:border-neutral-800">
                <div v-if="!messages.length" class="p-4 text-center text-xs text-neutral-400">Inbox empty. Send a mail from your app.</div>
                <button
                    v-for="msg in messages"
                    :key="msg.ID"
                    type="button"
                    class="block w-full border-b border-neutral-100 px-3 py-2 text-left dark:border-neutral-900"
                    :class="selectedId === msg.ID ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-900'"
                    @click="select(msg.ID)"
                >
                    <div class="flex items-center gap-1.5">
                        <span v-if="!msg.Read" class="h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500"></span>
                        <span class="truncate text-xs font-medium text-neutral-800 dark:text-neutral-100">{{ msg.Subject || '(no subject)' }}</span>
                    </div>
                    <div class="mt-0.5 truncate text-[11px] text-neutral-500">{{ fromLabel(msg) }}</div>
                    <div class="truncate text-[11px] text-neutral-400">{{ msg.Snippet }}</div>
                </button>
            </div>

            <!-- Detail -->
            <div class="flex min-w-0 flex-1 flex-col">
                <div v-if="!detail" class="flex flex-1 items-center justify-center text-sm text-neutral-400">Select a message.</div>
                <template v-else>
                    <div class="border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
                        <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ detail.Subject || '(no subject)' }}</div>
                        <div class="mt-0.5 text-xs text-neutral-500">
                            {{ detail.From?.Address }} →
                            {{ (detail.To || []).map((t) => t.Address).join(', ') }}
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
                            <span v-if="detail.Attachments?.length" class="ml-2 self-center text-[11px] text-neutral-400">
                                📎 {{ detail.Attachments.length }}
                            </span>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto">
                        <iframe
                            v-if="bodyTab === 'html'"
                            :srcdoc="detail.HTML"
                            sandbox=""
                            class="h-full w-full border-0 bg-white"
                        ></iframe>
                        <pre v-else-if="bodyTab === 'text'" class="whitespace-pre-wrap break-words p-3 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ detail.Text || '(no text part)' }}</pre>
                        <pre v-else class="whitespace-pre-wrap break-words p-3 font-mono text-xs text-neutral-500">{{ sourceText ?? 'Loading…' }}</pre>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
