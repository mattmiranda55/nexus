// Session-wide dump collection. Lives at module level (shared reactive refs)
// rather than inside the Dumps tab, because tabs are v-if-destroyed on switch —
// dumps arriving while you're elsewhere must still be captured (and counted).
//
// The stream: `nexus:dump-server` ChildProcess → JSON lines on stdout →
// Electron MessageReceived events → line reassembly → these refs.

import { ref } from 'vue';
import { postJson } from './http.js';
import { createLineBuffer } from './lineBuffer.js';
import { nativeAvailable, onChildProcessMessage } from './nativeEvents.js';

const MAX_ENTRIES = 500;

export const dumps = ref([]); // newest first: { id, ts, source, text }
export const serverState = ref('idle'); // idle | starting | ready | error | unavailable
export const serverError = ref('');
export const paused = ref(false);

let started = false;
let nextId = 1;

function handleLine(line) {
    let msg;
    try {
        msg = JSON.parse(line);
    } catch {
        return; // stray non-JSON stdout (deprecation notice etc.)
    }

    if (msg.type === 'ready') {
        serverState.value = 'ready';
    } else if (msg.type === 'error') {
        serverState.value = 'error';
        serverError.value = msg.message ?? 'Dump server failed to start';
    } else if (msg.type === 'dump' && !paused.value) {
        dumps.value.unshift({ id: nextId++, ts: msg.ts, source: msg.source ?? {}, text: msg.text ?? '' });
        if (dumps.value.length > MAX_ENTRIES) dumps.value.length = MAX_ENTRIES;
    }
}

/**
 * Start the receiver once per session (idempotent). Called from Console.vue
 * on mount so collection begins before the Dumps tab is ever opened.
 */
export async function startDumpStream() {
    if (started) return;
    started = true;

    if (!nativeAvailable()) {
        serverState.value = 'unavailable';
        return;
    }

    serverState.value = 'starting';
    onChildProcessMessage('dumps', createLineBuffer(handleLine));

    const { ok, data } = await postJson('/dumps/start');
    if (!ok) {
        serverState.value = 'error';
        serverError.value = data?.error ?? 'Could not start the dump server';
    }
}

export function clearDumps() {
    dumps.value = [];
}
