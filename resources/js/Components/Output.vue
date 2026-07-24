<script setup>
// Result panel. Given a structured envelope it offers the best views — Table
// for row sets, Tree for nested structures, SQL for captured queries — and
// always keeps a Raw tab for CLI parity with `artisan tinker`.
import { computed, ref, watch } from 'vue';
import OutputTable from './OutputTable.vue';
import TreeNode from './TreeNode.vue';
import OutputQueries from './OutputQueries.vue';
import RunLogCorrelation from './RunLogCorrelation.vue';

const props = defineProps({
    // { envelope: object|null, raw: string }
    result: { type: Object, default: () => ({ envelope: null, raw: '' }) },
    running: { type: Boolean, default: false },
});

const STRUCTURED = ['list', 'assoc', 'collection', 'model', 'object'];

const envelope = computed(() => props.result?.envelope ?? null);
const raw = computed(() => props.result?.raw ?? '');
const root = computed(() => envelope.value?.root ?? null);
const table = computed(() => envelope.value?.table ?? null);
const queries = computed(() => envelope.value?.queries ?? []);
const meta = computed(() => envelope.value?.meta ?? null);

const hasTree = computed(() => !!root.value && STRUCTURED.includes(root.value.kind));

const views = computed(() => {
    const v = [];
    if (table.value) v.push({ key: 'table', label: 'Table' });
    if (hasTree.value) v.push({ key: 'tree', label: 'Tree' });
    if (queries.value.length) v.push({ key: 'queries', label: `SQL (${queries.value.length})` });
    v.push({ key: 'raw', label: 'Raw' });
    return v;
});

const active = ref('raw');

// Whenever a fresh result lands, jump to the richest available view.
watch(
    () => props.result,
    () => {
        active.value = table.value ? 'table' : hasTree.value ? 'tree' : 'raw';
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex h-full flex-col bg-neutral-50 dark:bg-neutral-950">
        <div v-if="running" class="p-3 font-mono text-xs text-neutral-400">Running…</div>

        <template v-else-if="envelope || raw">
            <!-- Tab bar + type chip -->
            <div class="flex items-center gap-2 border-b border-neutral-200 px-2 py-1 dark:border-neutral-800">
                <button
                    v-for="v in views"
                    :key="v.key"
                    type="button"
                    class="rounded px-2 py-1 text-xs"
                    :class="active === v.key ? 'bg-neutral-200 font-medium text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'"
                    @click="active = v.key"
                >
                    {{ v.label }}
                </button>
                <span
                    v-if="meta"
                    class="ml-auto truncate rounded bg-neutral-100 px-2 py-0.5 font-mono text-[11px] text-neutral-500 dark:bg-neutral-900"
                    :title="meta.phpType"
                >{{ meta.phpType }}</span>
            </div>

            <!-- Active view -->
            <div class="min-h-0 flex-1 overflow-hidden">
                <OutputTable v-if="active === 'table' && table" :table="table" />

                <div v-else-if="active === 'tree' && root" class="h-full overflow-auto p-2 font-mono text-xs">
                    <TreeNode :node="root" :depth="0" />
                </div>

                <OutputQueries v-else-if="active === 'queries'" :queries="queries" />

                <div v-else class="h-full overflow-auto p-3 font-mono text-xs">
                    <pre class="whitespace-pre-wrap break-words text-neutral-800 dark:text-neutral-200">{{ raw }}</pre>
                </div>
            </div>
        </template>

        <div v-else class="p-3 font-mono text-xs text-neutral-400">Output will appear here. Press ⌘↵ to run.</div>

        <RunLogCorrelation v-if="!running && result?.logged" :text="result.logged" />
    </div>
</template>
