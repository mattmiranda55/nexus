<script setup>
// Run history for the active project: every tinker run, newest first, with
// status, duration, and age. Selecting a run restores its code into the
// editor — it never re-executes automatically (restored code may be destructive).
import { onMounted, ref } from 'vue';
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
    await deleteJson('/history');
    runs.value = [];
}

function age(iso) {
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

function preview(code) {
    const line = code.split('\n').find((l) => l.trim()) ?? '';
    return line.length > 80 ? line.slice(0, 80) + '…' : line;
}

onMounted(load);
</script>

<template>
    <div class="nx-scrim nx-fade fixed inset-0 z-50" @click="emit('close')">
        <div
            class="nx-plate nx-rise mx-auto mt-[12vh] flex max-h-[62vh] w-[42rem] max-w-[92vw] flex-col overflow-hidden"
            @click.stop
        >
            <div class="flex shrink-0 items-center gap-3 border-b border-rule bg-paper px-4 py-2.5">
                <span class="h-2 w-2 shrink-0 rotate-45 bg-accent"></span>
                <h2 class="font-display text-[15px] text-ink">Run history</h2>
                <span class="nx-leader"></span>
                <button
                    v-if="runs.length"
                    type="button"
                    class="nx-btn nx-btn-danger"
                    @click="clearAll"
                >
                    Clear all
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div v-if="status === 'loading'" class="space-y-2 p-4">
                    <div class="nx-skeleton h-2.5 w-2/5"></div>
                    <div class="nx-skeleton h-2.5 w-3/5"></div>
                    <div class="nx-skeleton h-2.5 w-1/3"></div>
                </div>
                <div v-else-if="status === 'error'" class="p-8 text-center">
                    <span class="nx-cap text-err">Could not load history</span>
                </div>
                <div v-else-if="!runs.length" class="p-8 text-center">
                    <span class="nx-cap">No runs yet</span>
                    <p class="mt-2 text-[11px] text-ink-3">History appears here after you run code.</p>
                </div>

                <button
                    v-for="run in runs"
                    :key="run.id"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-rule px-4 py-2 text-left last:border-0 hover:bg-accent-soft"
                    title="Restore into the editor (does not run)"
                    @click="emit('restore', run)"
                >
                    <span
                        class="h-2.5 w-[3px] shrink-0"
                        :class="run.ok ? 'bg-ok' : 'bg-err'"
                        :title="run.ok ? 'Completed' : 'Failed'"
                    ></span>
                    <code class="truncate font-mono text-[11.5px] text-ink">{{ preview(run.code) }}</code>
                    <span class="ml-auto shrink-0 font-mono text-[10px] tabular-nums text-ink-3">
                        {{ run.duration_ms }}ms
                        <span class="text-rule-2">·</span>
                        {{ age(run.created_at) }}
                    </span>
                </button>
            </div>

            <div class="shrink-0 border-t border-rule bg-paper px-4 py-2">
                <p class="nx-cap normal-case tracking-normal">
                    Click a run to restore its code. Nothing re-runs until you press
                    <kbd class="nx-kbd">⌘↵</kbd>.
                </p>
            </div>
        </div>
    </div>
</template>
