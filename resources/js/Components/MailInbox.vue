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

// Mailpit binds in well under a second, so poll tightly at first and back off
// rather than sleeping a flat second eight times over. Every one of these
// round-trips occupies the single-threaded PHP server, so the old version could
// leave the whole app unresponsive for ~9s before admitting Mailpit wasn't
// there — and it re-ran on every project switch.
const STARTUP_BACKOFF_MS = [100, 200, 300, 500, 800, 1200];

async function ensureRunning() {
    phase.value = 'starting';
    applyState((await postJson('/mail/start')).data);

    // A missing binary is terminal — no amount of waiting will conjure one.
    if (state.value.source === 'missing') { phase.value = 'missing'; return; }

    for (const delay of STARTUP_BACKOFF_MS) {
        if (state.value.running) break;
        await wait(delay);
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

// Fallback for when the direct websocket to Mailpit won't hold. Each tick is a
// proxied round-trip (renderer → PHP → Mailpit → back) on a server that can
// only do one thing at a time, so don't spend them while nobody is looking:
// a backgrounded window polls nothing and catches up on focus.
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
    try { ws?.close(); } catch { /* ignore */ }
    ws = null;
    clearInterval(pollTimer);
    clearTimeout(refreshTimer);
    document.removeEventListener('visibilitychange', pollOnReveal);
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
        <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-rule bg-paper px-3 py-1.5">
            <span class="flex shrink-0 items-center gap-1.5">
                <span
                    class="h-1.5 w-1.5"
                    :class="phase === 'ready' ? 'bg-ok' : phase === 'starting' ? 'bg-accent nx-blink' : 'bg-ink-3'"
                ></span>
                <span class="nx-cap" :class="phase === 'ready' ? 'text-ok' : ''">Mailpit</span>
                <span v-if="state.source === 'detected'" class="nx-cap">· existing</span>
            </span>

            <span v-if="hasEnv && !connected" class="nx-cap text-warn">· project not wired</span>

            <span class="nx-leader"></span>

            <div class="flex shrink-0 items-center gap-1.5">
                <button
                    v-if="phase === 'ready' && hasEnv && !connected"
                    type="button"
                    class="nx-btn nx-btn-accent"
                    @click="connectEnv"
                >
                    Connect this app
                </button>
                <button
                    v-if="phase === 'ready'"
                    type="button"
                    class="nx-btn"
                    @click="loadMessages"
                >
                    Refresh
                </button>
                <button
                    v-if="phase === 'ready' && messages.length"
                    type="button"
                    class="nx-btn nx-btn-danger"
                    @click="clearInbox"
                >
                    Clear
                </button>
            </div>
        </div>

        <!-- States -->
        <div v-if="!activeProject" class="flex flex-1 flex-col items-center justify-center gap-2.5 bg-surface">
            <span class="nx-cap">No project</span>
            <p class="text-[11px] text-ink-3">Select a project to view its mail.</p>
        </div>
        <div v-else-if="phase === 'starting' || phase === 'init'" class="flex flex-1 flex-col items-center justify-center gap-3 bg-surface">
            <div class="nx-skeleton h-2.5 w-40"></div>
            <span class="nx-cap">{{ phase === 'starting' ? 'Starting Mailpit' : 'Checking Mailpit' }}</span>
        </div>
        <div v-else-if="phase === 'missing'" class="flex flex-1 items-center justify-center bg-surface p-6">
            <div class="max-w-md border border-dashed border-rule-2 p-5 text-center">
                <p class="nx-cap">Mailpit not found</p>
                <p class="mt-3 text-[12px] leading-relaxed text-ink-2">
                    Nothing is running and no binary was located. Install
                    <a class="text-key underline decoration-dotted underline-offset-2" href="https://mailpit.axllent.org" target="_blank" rel="noopener">Mailpit</a>
                    (Herd bundles it), or set
                    <code class="border border-rule bg-raised px-1 font-mono text-[11px]">NEXUS_MAILPIT_PATH</code>,
                    then retry.
                </p>
                <button type="button" class="nx-btn mt-4" @click="init">Retry</button>
            </div>
        </div>
        <div v-else-if="phase === 'error'" class="flex flex-1 flex-col items-center justify-center gap-3 bg-surface p-6 text-center">
            <span class="nx-cap text-err">Unreachable</span>
            <p class="text-[11px] text-ink-2">Couldn't reach Mailpit.</p>
            <button type="button" class="nx-btn" @click="init">Retry</button>
        </div>

        <!-- Inbox -->
        <div v-else class="flex min-h-0 flex-1">
            <!-- List -->
            <div class="flex w-[17rem] shrink-0 flex-col border-r border-rule bg-paper">
                <div class="flex shrink-0 items-center gap-2.5 border-b border-rule px-3 py-1.5">
                    <span class="nx-cap">Inbox</span>
                    <span class="nx-leader"></span>
                    <span v-if="messages.length" class="nx-cap tabular-nums">{{ messages.length }}</span>
                </div>

                <div class="min-h-0 flex-1 overflow-auto">
                    <div v-if="!messages.length" class="mx-3 mt-3 border border-dashed border-rule-2 px-3 py-6 text-center">
                        <p class="nx-cap">Empty</p>
                        <p class="mt-2 text-[11px] leading-snug text-ink-3">Send a mail from your app.</p>
                    </div>

                    <button
                        v-for="msg in messages"
                        :key="msg.ID"
                        type="button"
                        class="block w-full border-b border-rule px-3 py-2 text-left transition-colors"
                        :class="selectedId === msg.ID ? 'nx-marked bg-raised' : 'hover:bg-raised/60'"
                        @click="select(msg.ID)"
                    >
                        <div class="flex items-center gap-1.5">
                            <span v-if="!msg.Read" class="h-1.5 w-1.5 shrink-0 bg-accent" title="Unread"></span>
                            <span
                                class="truncate text-[12.5px] leading-tight"
                                :class="msg.Read ? 'text-ink-2' : 'font-medium text-ink'"
                            >{{ msg.Subject || '(no subject)' }}</span>
                        </div>
                        <div class="mt-1 truncate font-mono text-[10px] text-ink-2">{{ fromLabel(msg) }}</div>
                        <div class="mt-0.5 truncate text-[11px] leading-snug text-ink-3">{{ msg.Snippet }}</div>
                    </button>
                </div>
            </div>

            <!-- Detail -->
            <div class="flex min-w-0 flex-1 flex-col bg-surface">
                <div v-if="!detail" class="flex flex-1 flex-col items-center justify-center gap-2.5">
                    <span class="h-2 w-2 rotate-45 border border-rule-2"></span>
                    <span class="nx-cap">Select a message</span>
                </div>
                <template v-else>
                    <div class="shrink-0 border-b border-rule bg-paper px-3 pt-2">
                        <div class="truncate text-[13px] font-medium text-ink">{{ detail.Subject || '(no subject)' }}</div>
                        <div class="mt-1 truncate font-mono text-[10.5px] text-ink-2">
                            {{ detail.From?.Address }}
                            <span class="text-accent">→</span>
                            {{ (detail.To || []).map((t) => t.Address).join(', ') }}
                        </div>
                        <div class="mt-2 flex h-7 items-stretch gap-4">
                            <button
                                v-for="t in bodyTabs"
                                :key="t.key"
                                type="button"
                                class="nx-tab"
                                :class="{ 'nx-tab-on': bodyTab === t.key }"
                                @click="bodyTab = t.key"
                            >
                                {{ t.label }}
                            </button>
                            <span class="nx-leader self-center"></span>
                            <span v-if="detail.Attachments?.length" class="nx-cap self-center">
                                📎 {{ detail.Attachments.length }}
                            </span>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto">
                        <!-- Mail HTML keeps its own white canvas; it isn't ours to re-theme. -->
                        <iframe
                            v-if="bodyTab === 'html'"
                            :srcdoc="detail.HTML"
                            sandbox=""
                            class="h-full w-full border-0 bg-white"
                        ></iframe>
                        <pre v-else-if="bodyTab === 'text'" class="whitespace-pre-wrap break-words p-3 font-mono text-[11.5px] leading-relaxed text-ink">{{ detail.Text || '(no text part)' }}</pre>
                        <pre v-else class="whitespace-pre-wrap break-words p-3 font-mono text-[10.5px] leading-relaxed text-ink-2">{{ sourceText ?? 'Loading…' }}</pre>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
