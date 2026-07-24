<script setup>
// C7 — database browser. Left: the connection's tables (db:show). Right: the
// selected table's rows (browsed through the structured tinker pipeline, so
// the grid is the same OutputTable as the REPL) or its schema (db:table).
// Read-only by design — writes belong in the tinker editor where they're explicit.
import { computed, ref, watch } from 'vue';
import { postJson } from '../../lib/http.js';
import { toTable } from '../../lib/table.js';
import OutputTable from '../OutputTable.vue';

const PAGE = 50;

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const emit = defineEmits(['run-code']);

const tables = ref([]);
const connection = ref(null);
const selected = ref(null);
const view = ref('rows'); // rows | schema
const offset = ref(0);

const status = ref('idle'); // idle | loading | ready | error
const detailStatus = ref('idle');
const error = ref('');
const detailError = ref('');

const rowsEnvelope = ref(null);
const schema = ref(null);

// db:show's table entries have shifted keys across Laravel versions; take
// whichever name/count field is present.
const normalizedTables = computed(() =>
    tables.value.map((t) => ({
        name: t.table ?? t.name ?? String(t),
        rows: t.rows ?? t.count ?? null,
    })),
);

async function loadTables() {
    status.value = 'loading';
    error.value = '';
    selected.value = null;
    rowsEnvelope.value = null;
    schema.value = null;

    const { ok, data } = await postJson('/workbench/db/tables');
    if (!ok) {
        status.value = 'error';
        error.value = data?.error ?? 'Failed to inspect the database';
        return;
    }

    const db = data?.database ?? {};
    tables.value = db.tables ?? [];
    connection.value = db.platform?.name ?? db.name ?? null;
    status.value = 'ready';

    if (normalizedTables.value.length) select(normalizedTables.value[0].name);
}

function select(name) {
    selected.value = name;
    offset.value = 0;
    schema.value = null;
    loadDetail();
}

async function loadDetail() {
    if (!selected.value) return;
    detailStatus.value = 'loading';
    detailError.value = '';

    if (view.value === 'rows') {
        const { ok, data } = await postJson('/workbench/db/rows', {
            table: selected.value,
            offset: offset.value,
        });
        rowsEnvelope.value = ok ? data?.envelope ?? null : null;
        if (!ok) detailError.value = data?.error ?? 'Query failed';
    } else if (!schema.value) {
        // Schema is immutable while browsing, so fetch it once per table.
        const { ok, data } = await postJson('/workbench/db/table', { table: selected.value });
        schema.value = ok ? data?.table ?? null : null;
        if (!ok) detailError.value = data?.error ?? 'Failed to read schema';
    }

    detailStatus.value = detailError.value ? 'error' : 'ready';
}

const rowsTable = computed(() => rowsEnvelope.value?.table ?? null);
const pageRowCount = computed(() => rowsTable.value?.rows?.length ?? 0);
const columnsTable = computed(() =>
    schema.value?.columns?.length ? toTable(schema.value.columns) : null,
);

function setView(v) {
    if (view.value === v) return;
    view.value = v;
    loadDetail();
}

function page(delta) {
    offset.value = Math.max(0, offset.value + delta * PAGE);
    loadDetail();
}

function openInTinker() {
    if (selected.value) {
        emit('run-code', `DB::table('${selected.value}')->limit(50)->get();`);
    }
}

watch(() => props.activeProject?.id, loadTables, { immediate: true });
</script>

<template>
    <div class="flex h-full min-h-0">
        <!-- Table list -->
        <div class="flex w-56 shrink-0 flex-col overflow-hidden border-r border-neutral-200 dark:border-neutral-800">
            <div class="flex items-center justify-between border-b border-neutral-200 px-3 py-1.5 text-[11px] text-neutral-500 dark:border-neutral-800">
                <span class="truncate">{{ connection ?? 'database' }}</span>
                <button type="button" class="rounded px-1.5 py-0.5 hover:bg-neutral-100 dark:hover:bg-neutral-800" title="Refresh" @click="loadTables">↺</button>
            </div>
            <div class="min-h-0 flex-1 overflow-auto">
                <div v-if="status === 'loading'" class="p-3 text-xs text-neutral-400">Inspecting database…</div>
                <div v-else-if="status === 'error'" class="p-3 text-xs text-red-500">{{ error }}</div>
                <div v-else-if="!normalizedTables.length" class="p-3 text-xs text-neutral-400">No tables found.</div>
                <button
                    v-for="t in normalizedTables"
                    :key="t.name"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left font-mono text-xs"
                    :class="selected === t.name ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-900'"
                    @click="select(t.name)"
                >
                    <span class="truncate">{{ t.name }}</span>
                    <span v-if="t.rows !== null" class="ml-auto shrink-0 text-[10px] text-neutral-400">{{ t.rows }}</span>
                </button>
            </div>
        </div>

        <!-- Detail -->
        <div class="flex min-w-0 flex-1 flex-col">
            <div v-if="!selected" class="flex flex-1 items-center justify-center text-sm text-neutral-400">
                Select a table.
            </div>

            <template v-else>
                <div class="flex items-center gap-2 border-b border-neutral-200 px-3 py-1.5 text-xs dark:border-neutral-800">
                    <span class="font-mono font-semibold text-neutral-800 dark:text-neutral-100">{{ selected }}</span>
                    <div class="ml-2 flex rounded-md bg-neutral-100 p-0.5 dark:bg-neutral-800">
                        <button
                            type="button"
                            class="rounded px-2 py-0.5"
                            :class="view === 'rows' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                            @click="setView('rows')"
                        >Rows</button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5"
                            :class="view === 'schema' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                            @click="setView('schema')"
                        >Schema</button>
                    </div>
                    <button
                        type="button"
                        class="ml-auto rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-500"
                        title="Load this query into the Tinker editor"
                        @click="openInTinker"
                    >
                        Open in Tinker →
                    </button>
                </div>

                <div v-if="detailStatus === 'loading'" class="p-4 text-sm text-neutral-400">Loading…</div>
                <div v-else-if="detailStatus === 'error'" class="p-4 text-sm text-red-500">{{ detailError }}</div>

                <template v-else-if="view === 'rows'">
                    <div class="min-h-0 flex-1 overflow-hidden">
                        <OutputTable v-if="rowsTable" :table="rowsTable" />
                        <div v-else class="p-4 text-sm text-neutral-400">No rows.</div>
                    </div>
                    <div class="flex items-center justify-center gap-3 border-t border-neutral-200 px-3 py-1.5 text-xs dark:border-neutral-800">
                        <button type="button" class="rounded px-2 py-0.5 hover:bg-neutral-100 disabled:opacity-40 dark:hover:bg-neutral-800" :disabled="offset === 0" @click="page(-1)">‹ Prev</button>
                        <span class="text-neutral-500">rows {{ offset + 1 }}–{{ offset + pageRowCount }}</span>
                        <button type="button" class="rounded px-2 py-0.5 hover:bg-neutral-100 disabled:opacity-40 dark:hover:bg-neutral-800" :disabled="pageRowCount < PAGE" @click="page(1)">Next ›</button>
                    </div>
                </template>

                <div v-else class="min-h-0 flex-1 overflow-auto">
                    <OutputTable v-if="columnsTable" :table="columnsTable" />
                    <div v-else class="p-4 text-sm text-neutral-400">No column metadata reported.</div>

                    <div v-if="schema?.indexes?.length" class="border-t border-neutral-200 p-3 dark:border-neutral-800">
                        <div class="mb-1 text-xs font-semibold text-neutral-500">Indexes</div>
                        <ul class="space-y-0.5 font-mono text-xs">
                            <li v-for="idx in schema.indexes" :key="idx.name ?? idx.index" class="text-neutral-700 dark:text-neutral-300">
                                <span class="text-sky-600 dark:text-sky-400">{{ idx.name ?? idx.index }}</span>
                                <span class="text-neutral-400"> — {{ (idx.columns ?? []).join(', ') }}</span>
                                <span v-if="idx.unique" class="text-amber-500"> unique</span>
                                <span v-if="idx.primary" class="text-emerald-500"> primary</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
