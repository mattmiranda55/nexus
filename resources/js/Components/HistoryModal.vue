<script setup>
// Run history for the active project: every tinker run, newest first, with
// status, duration, and age. Selecting a run restores its code into the
// editor — it never re-executes automatically (restored code may be destructive).
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { codePreview } from '../lib/codePreview.js';
import { deleteJson, getJson } from '../lib/http.js';

const emit = defineEmits(['close', 'restore']);

const runs = ref([]);
const status = ref('loading'); // loading | ready | error

async function load() {
    status.value = 'loading';
    const { ok, data } = await getJson('/history');
    runs.value = ok ? data?.runs ?? [] : [];
    status.value = ok ? 'ready' : 'error';
}

async function clearAll() {
    if (!window.confirm('Delete the run history for this project?')) return;
    const { ok } = await deleteJson('/history');
    if (ok) runs.value = [];
    else status.value = 'error';
}

function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function age(iso) {
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

// Comments are left out of the list (the default buffer starts with one, so
// every run looked alike); restoring a run still brings back its full code.
function preview(code) {
    return codePreview(code) || '(comments only)';
}

onMounted(load);
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/30" @click="emit('close')">
        <div
            class="mx-auto mt-[12vh] flex max-h-[60vh] w-[40rem] max-w-[90vw] flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-2xl dark:border-neutral-700 dark:bg-neutral-900"
            role="dialog"
            aria-modal="true"
            aria-labelledby="history-title"
            @click.stop
        >
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-2.5 dark:border-neutral-700">
                <h2 id="history-title" class="text-sm font-semibold">Run history</h2>
                <button
                    v-if="runs.length"
                    type="button"
                    class="rounded px-2 py-1 text-xs text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                    @click="clearAll"
                >
                    Clear all
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto py-1">
                <div v-if="status === 'loading'" class="px-4 py-6 text-center text-sm text-neutral-400">Loading…</div>
                <div v-else-if="status === 'error'" class="px-4 py-6 text-center text-sm text-red-500">The history request failed. Close and reopen to try again.</div>
                <div v-else-if="!runs.length" class="px-4 py-6 text-center text-sm text-neutral-400">
                    No runs yet — history appears here after you run code.
                </div>

                <button
                    v-for="run in runs"
                    :key="run.id"
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-neutral-100 dark:hover:bg-neutral-800"
                    title="Restore into the editor (does not run)"
                    @click="emit('restore', run)"
                >
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                        :class="run.ok ? 'bg-emerald-500' : 'bg-red-500'"
                        :title="run.ok ? 'Completed' : 'Failed'"
                    ></span>
                    <code class="truncate font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ preview(run.code) }}</code>
                    <span class="ml-auto shrink-0 text-[10px] text-neutral-400">{{ run.duration_ms }}ms · {{ age(run.created_at) }}</span>
                </button>
            </div>

            <div class="border-t border-neutral-200 px-4 py-1.5 text-[10px] text-neutral-400 dark:border-neutral-700">
                Click a run to restore its code into the editor. Nothing re-runs until you press ⌘↵.
            </div>
        </div>
    </div>
</template>
