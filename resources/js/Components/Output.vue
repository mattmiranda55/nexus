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
    <div class="flex h-full min-h-0 flex-col bg-surface">
        <!-- Plate 02 legend. Always present, so the pane keeps its identity
             before the first run; the view tabs join it once there's a result. -->
        <div class="nx-caption h-[30px]">
            <span class="nx-caption-idx">02</span>
            <span class="nx-cap">Result</span>

            <nav v-if="!running && (envelope || raw)" class="ml-2 flex h-full items-stretch gap-4">
                <button
                    v-for="v in views"
                    :key="v.key"
                    type="button"
                    class="nx-tab"
                    :class="{ 'nx-tab-on': active === v.key }"
                    @click="active = v.key"
                >
                    {{ v.label }}
                </button>
            </nav>

            <span class="nx-leader"></span>

            <span
                v-if="meta && !running"
                class="nx-cap max-w-[14rem] truncate normal-case tracking-normal text-ink-2"
                :title="meta.phpType"
            >{{ meta.phpType }}</span>
        </div>

        <!-- Running: placeholder rules rather than a bare word -->
        <div v-if="running" class="min-h-0 flex-1 space-y-2 p-3">
            <div class="nx-skeleton h-2.5 w-1/3"></div>
            <div class="nx-skeleton h-2.5 w-3/5"></div>
            <div class="nx-skeleton h-2.5 w-1/4"></div>
        </div>

        <!-- Active view -->
        <div v-else-if="envelope || raw" class="min-h-0 flex-1 overflow-hidden">
            <OutputTable v-if="active === 'table' && table" :table="table" />

            <div v-else-if="active === 'tree' && root" class="h-full overflow-auto p-2.5 font-mono text-[11.5px]">
                <TreeNode :node="root" :depth="0" />
            </div>

            <OutputQueries v-else-if="active === 'queries'" :queries="queries" />

            <div v-else class="h-full overflow-auto p-3 font-mono text-[11.5px] leading-relaxed">
                <pre class="whitespace-pre-wrap break-words text-ink">{{ raw }}</pre>
            </div>
        </div>

        <!-- Nothing run yet -->
        <div v-else class="flex min-h-0 flex-1 flex-col items-center justify-center gap-2.5 p-4 text-center">
            <span class="h-2 w-2 rotate-45 border border-rule-2"></span>
            <span class="nx-cap">Awaiting run</span>
            <span class="flex items-center gap-1.5 text-[11px] text-ink-3">
                Press <kbd class="nx-kbd">⌘↵</kbd> to evaluate
            </span>
        </div>

        <RunLogCorrelation v-if="!running && result?.logged" :text="result.logged" />
    </div>
</template>
