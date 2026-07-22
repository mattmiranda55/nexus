<script setup>
// C1 — model explorer. Left: discovered model classes. Right: the selected
// model's attributes (grid) + relations, with a "Query" button that drops a
// starter query into the Tinker editor and runs it through the table view.
import { computed, ref, watch } from 'vue';
import { postJson } from '../../lib/http.js';
import { toTable } from '../../lib/table.js';
import OutputTable from '../OutputTable.vue';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const emit = defineEmits(['run-code']);

const models = ref([]);
const selected = ref(null);
const detail = ref(null);
const status = ref('idle'); // idle | loading | ready | error
const detailStatus = ref('idle');
const error = ref('');

const ATTR_COLUMNS = ['name', 'type', 'nullable', 'default', 'fillable', 'hidden', 'cast'];
const attributeTable = computed(() =>
    detail.value?.attributes?.length ? toTable(detail.value.attributes, ATTR_COLUMNS) : null,
);

async function loadModels() {
    status.value = 'loading';
    error.value = '';
    detail.value = null;
    selected.value = null;
    const { ok, data } = await postJson('/workbench/models');
    if (!ok) {
        status.value = 'error';
        error.value = data?.error ?? 'Failed to load models';
        return;
    }
    models.value = data?.models ?? [];
    status.value = 'ready';
    if (models.value.length) select(models.value[0]);
}

async function select(name) {
    selected.value = name;
    detailStatus.value = 'loading';
    const { ok, data } = await postJson('/workbench/model', { model: name });
    detail.value = ok ? data?.model ?? null : null;
    detailStatus.value = ok && detail.value ? 'ready' : 'error';
}

function runQuery() {
    if (selected.value) emit('run-code', `${selected.value}::query()->limit(50)->get();`);
}

watch(() => props.activeProject?.id, loadModels, { immediate: true });
</script>

<template>
    <div class="flex h-full min-h-0">
        <!-- Model list -->
        <div class="w-48 shrink-0 overflow-auto border-r border-neutral-200 dark:border-neutral-800">
            <div v-if="status === 'loading'" class="p-3 text-xs text-neutral-400">Loading…</div>
            <div v-else-if="status === 'error'" class="p-3 text-xs text-red-500">{{ error }}</div>
            <div v-else-if="!models.length" class="p-3 text-xs text-neutral-400">No models in app/Models.</div>
            <button
                v-for="name in models"
                :key="name"
                type="button"
                class="block w-full truncate px-3 py-1.5 text-left font-mono text-xs"
                :class="selected === name ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-900'"
                @click="select(name)"
            >
                {{ name }}
            </button>
        </div>

        <!-- Detail -->
        <div class="flex min-w-0 flex-1 flex-col">
            <div v-if="detailStatus === 'loading'" class="p-4 text-sm text-neutral-400">Loading {{ selected }}…</div>
            <div v-else-if="detailStatus === 'error'" class="p-4 text-sm text-red-500">Could not introspect {{ selected }}.</div>

            <template v-else-if="detail">
                <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
                    <span class="font-mono font-semibold text-neutral-800 dark:text-neutral-100">{{ detail.class ?? selected }}</span>
                    <span v-if="detail.table" class="text-neutral-500">table: <span class="font-mono">{{ detail.table }}</span></span>
                    <button
                        type="button"
                        class="ml-auto rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-500"
                        @click="runQuery"
                    >
                        Query 50 rows →
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto">
                    <OutputTable v-if="attributeTable" :table="attributeTable" />
                    <div v-else class="p-4 text-sm text-neutral-400">No attributes reported.</div>

                    <div v-if="detail.relations?.length" class="border-t border-neutral-200 p-3 dark:border-neutral-800">
                        <div class="mb-1 text-xs font-semibold text-neutral-500">Relations</div>
                        <ul class="space-y-0.5 font-mono text-xs">
                            <li v-for="rel in detail.relations" :key="rel.name" class="text-neutral-700 dark:text-neutral-300">
                                <span class="text-sky-600 dark:text-sky-400">{{ rel.name }}</span>
                                <span class="text-neutral-400"> — {{ rel.type }}</span>
                                <span v-if="rel.related"> → {{ rel.related }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
