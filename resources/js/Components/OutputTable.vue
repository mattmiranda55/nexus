<script setup>
// Sortable, filterable, paginated grid for tabular envelopes (collections and
// lists of associative rows). Rows arrive as arrays aligned to `columns`.
// CSV/JSON export is entirely client-side over the current filtered+sorted set.
import { computed, ref, watch } from 'vue';

const props = defineProps({
    table: { type: Object, required: true }, // { columns, rows, count, truncated }
});

const PAGE_SIZE = 50;

const query = ref('');
const sortCol = ref(-1); // column index, -1 = natural order
const sortDir = ref('asc');
const page = ref(1);

// Reset view state whenever a new result replaces the old one.
watch(
    () => props.table,
    () => {
        query.value = '';
        sortCol.value = -1;
        sortDir.value = 'asc';
        page.value = 1;
    },
);

const cellText = (v) => {
    if (v === null || v === undefined) return '';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    return String(v);
};

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.table.rows;
    return props.table.rows.filter((row) => row.some((cell) => cellText(cell).toLowerCase().includes(q)));
});

const sorted = computed(() => {
    if (sortCol.value < 0) return filtered.value;
    const col = sortCol.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    // Copy before sorting so we never mutate the prop array.
    return [...filtered.value].sort((a, b) => {
        const x = a[col];
        const y = b[col];
        if (x === null || x === undefined) return 1; // nulls sink
        if (y === null || y === undefined) return -1;
        if (typeof x === 'number' && typeof y === 'number') return (x - y) * dir;
        return cellText(x).localeCompare(cellText(y), undefined, { numeric: true }) * dir;
    });
});

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / PAGE_SIZE)));
const paged = computed(() => {
    const start = (page.value - 1) * PAGE_SIZE;
    return sorted.value.slice(start, start + PAGE_SIZE);
});

watch([sorted, query], () => {
    if (page.value > pageCount.value) page.value = 1;
});

function toggleSort(col) {
    if (sortCol.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortCol.value = col;
        sortDir.value = 'asc';
    }
}

function download(filename, text, type) {
    const blob = new Blob([text], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function csvEscape(v) {
    const s = cellText(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportCsv() {
    const { columns } = props.table;
    const lines = [columns.map(csvEscape).join(',')];
    for (const row of sorted.value) lines.push(row.map(csvEscape).join(','));
    download('nexus-result.csv', lines.join('\n'), 'text/csv');
}

function exportJson() {
    const { columns } = props.table;
    const objs = sorted.value.map((row) => Object.fromEntries(columns.map((c, i) => [c, row[i]])));
    download('nexus-result.json', JSON.stringify(objs, null, 2), 'application/json');
}
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Controls -->
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
            <input
                v-model="query"
                type="search"
                placeholder="Filter rows…"
                class="w-48 rounded border border-neutral-300 bg-white px-2 py-1 text-neutral-800 placeholder:text-neutral-400 focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            />
            <span class="text-neutral-500">
                {{ sorted.length }}<span v-if="query"> / {{ table.rows.length }}</span> rows
                <span v-if="table.truncated" class="text-amber-500">(showing first {{ table.rows.length }} of {{ table.count }})</span>
            </span>
            <div class="ml-auto flex items-center gap-1">
                <button type="button" class="rounded border border-neutral-300 px-2 py-1 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="exportCsv">CSV</button>
                <button type="button" class="rounded border border-neutral-300 px-2 py-1 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="exportJson">JSON</button>
            </div>
        </div>

        <!-- Grid -->
        <div class="min-h-0 flex-1 overflow-auto">
            <table class="w-full border-collapse text-left font-mono text-xs">
                <thead class="sticky top-0 z-10 bg-neutral-100 dark:bg-neutral-900">
                    <tr>
                        <th
                            v-for="(col, i) in table.columns"
                            :key="col"
                            class="cursor-pointer select-none border-b border-neutral-200 px-3 py-1.5 font-semibold text-neutral-600 hover:text-neutral-900 dark:border-neutral-800 dark:text-neutral-300 dark:hover:text-white"
                            @click="toggleSort(i)"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ col }}
                                <span v-if="sortCol === i" class="text-emerald-500">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, r) in paged"
                        :key="r"
                        class="odd:bg-white even:bg-neutral-50 hover:bg-emerald-50 dark:odd:bg-neutral-950 dark:even:bg-neutral-900/50 dark:hover:bg-emerald-950/30"
                    >
                        <td v-for="(cell, c) in row" :key="c" class="max-w-xs truncate border-b border-neutral-100 px-3 py-1 align-top dark:border-neutral-800/60">
                            <span v-if="cell === null || cell === undefined" class="italic text-neutral-400">null</span>
                            <span v-else-if="typeof cell === 'boolean'" class="text-purple-500 dark:text-purple-400">{{ cell }}</span>
                            <span v-else-if="typeof cell === 'number'" class="text-amber-600 dark:text-amber-400">{{ cell }}</span>
                            <span v-else class="text-neutral-800 dark:text-neutral-200" :title="String(cell)">{{ cell }}</span>
                        </td>
                    </tr>
                    <tr v-if="!paged.length">
                        <td :colspan="table.columns.length" class="px-3 py-4 text-center text-neutral-400">No matching rows</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="pageCount > 1" class="flex items-center justify-center gap-3 border-t border-neutral-200 px-3 py-1.5 text-xs dark:border-neutral-800">
            <button type="button" class="rounded px-2 py-0.5 disabled:opacity-40 hover:bg-neutral-100 dark:hover:bg-neutral-800" :disabled="page <= 1" @click="page--">‹ Prev</button>
            <span class="text-neutral-500">Page {{ page }} / {{ pageCount }}</span>
            <button type="button" class="rounded px-2 py-0.5 disabled:opacity-40 hover:bg-neutral-100 dark:hover:bg-neutral-800" :disabled="page >= pageCount" @click="page++">Next ›</button>
        </div>
    </div>
</template>
