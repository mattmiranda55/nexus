<script setup>
// C5 — migration status + guarded migrate/rollback. Write actions confirm
// first (they mutate the target database).
import { computed, ref, watch } from 'vue';
import { postJson } from '../../lib/http.js';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const migrations = ref([]);
const pending = ref(0);
const status = ref('idle'); // idle | loading | ready | error
const error = ref('');
const busy = ref(false);
const actionOutput = ref('');

async function load() {
    status.value = 'loading';
    error.value = '';
    const { ok, data } = await postJson('/workbench/migrations');
    if (!ok) {
        status.value = 'error';
        error.value = data?.error ?? 'Failed to load migration status';
        return;
    }
    migrations.value = data?.migrations ?? [];
    pending.value = data?.pending ?? 0;
    status.value = 'ready';
}

async function act(endpoint, verb) {
    if (busy.value) return;
    if (!window.confirm(`Run "${verb}" on ${props.activeProject?.name ?? 'this project'}'s database?`)) return;

    busy.value = true;
    actionOutput.value = '';
    const { ok, data } = await postJson(endpoint);
    actionOutput.value = data?.output ?? (ok ? 'Done.' : 'Failed.');
    busy.value = false;
    await load();
}

watch(() => props.activeProject?.id, load, { immediate: true });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
            <span class="text-neutral-500">
                {{ migrations.length }} migrations ·
                <span :class="pending ? 'text-amber-500' : 'text-emerald-500'">{{ pending }} pending</span>
            </span>
            <div class="ml-auto flex gap-1">
                <button
                    type="button"
                    class="rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-500 disabled:opacity-40"
                    :disabled="busy || !pending"
                    @click="act('/workbench/migrate', 'migrate')"
                >
                    Migrate
                </button>
                <button
                    type="button"
                    class="rounded border border-red-400 px-2 py-1 text-red-600 hover:bg-red-50 disabled:opacity-40 dark:border-red-500/60 dark:text-red-400 dark:hover:bg-red-950/40"
                    :disabled="busy"
                    @click="act('/workbench/rollback', 'rollback last batch')"
                >
                    Rollback
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-2 font-mono text-xs">
            <div v-if="status === 'loading'" class="p-2 text-neutral-400">Loading…</div>
            <div v-else-if="status === 'error'" class="p-2 text-red-500">{{ error }}</div>

            <ul v-else class="space-y-0.5">
                <li v-for="m in migrations" :key="m.name" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-neutral-100 dark:hover:bg-neutral-900">
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                        :class="m.ran ? 'bg-emerald-500' : 'bg-amber-500'"
                    ></span>
                    <span class="truncate text-neutral-700 dark:text-neutral-300">{{ m.name }}</span>
                    <span class="ml-auto shrink-0 text-neutral-400">
                        <span v-if="m.batch">[{{ m.batch }}] </span>{{ m.ran ? 'Ran' : 'Pending' }}
                    </span>
                </li>
            </ul>

            <pre v-if="actionOutput" class="mt-3 whitespace-pre-wrap break-words border-t border-neutral-200 pt-2 text-neutral-500 dark:border-neutral-800">{{ actionOutput }}</pre>
        </div>
    </div>
</template>
