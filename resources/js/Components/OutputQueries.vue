<script setup>
// Lists the SQL captured during a run and flags likely N+1 patterns: the same
// query shape (numbers/quoted literals normalised out) firing more than a few
// times. Data comes from DB::getQueryLog() folded into the envelope.
import { computed } from 'vue';

const props = defineProps({
    queries: { type: Array, default: () => [] }, // [{ sql, bindings, time }]
});

const N1_THRESHOLD = 3;

const normalize = (sql) =>
    (sql || '')
        .replace(/'[^']*'/g, '?')
        .replace(/\b\d+\b/g, '?')
        .replace(/\s+/g, ' ')
        .trim();

// How many times each normalised shape appears, so each row can show ×N.
const shapeCounts = computed(() => {
    const counts = {};
    for (const q of props.queries) {
        const key = normalize(q.sql);
        counts[key] = (counts[key] || 0) + 1;
    }
    return counts;
});

const totalTime = computed(() =>
    props.queries.reduce((sum, q) => sum + (Number(q.time) || 0), 0).toFixed(2),
);

// Distinct shapes that fired more than the threshold — the N+1 suspects.
const suspects = computed(() =>
    Object.entries(shapeCounts.value)
        .filter(([, n]) => n > N1_THRESHOLD)
        .map(([shape, n]) => ({ shape, n }))
        .sort((a, b) => b.n - a.n),
);
</script>

<template>
    <div class="h-full overflow-auto p-3 font-mono text-xs">
        <div class="mb-2 flex items-center gap-3 text-neutral-500">
            <span>{{ queries.length }} quer{{ queries.length === 1 ? 'y' : 'ies' }}</span>
            <span>· {{ totalTime }} ms total</span>
        </div>

        <div v-if="suspects.length" class="mb-3 rounded border border-amber-400/50 bg-amber-50 p-2 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            <div class="mb-1 font-semibold">⚠ Possible N+1 ({{ suspects.length }})</div>
            <div v-for="s in suspects" :key="s.shape" class="truncate" :title="s.shape">
                ×{{ s.n }} — {{ s.shape }}
            </div>
        </div>

        <ol class="space-y-1">
            <li
                v-for="(q, i) in queries"
                :key="i"
                class="rounded border border-neutral-200 p-2 dark:border-neutral-800"
            >
                <div class="flex items-start gap-2">
                    <span class="shrink-0 text-neutral-400">{{ i + 1 }}.</span>
                    <code class="flex-1 whitespace-pre-wrap break-words text-neutral-800 dark:text-neutral-200">{{ q.sql }}</code>
                    <span
                        v-if="shapeCounts[normalize(q.sql)] > N1_THRESHOLD"
                        class="shrink-0 rounded bg-amber-200 px-1 text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                    >×{{ shapeCounts[normalize(q.sql)] }}</span>
                    <span v-if="q.time !== null && q.time !== undefined" class="shrink-0 text-neutral-400">{{ q.time }}ms</span>
                </div>
                <div v-if="q.bindings?.length" class="ml-5 mt-1 text-neutral-500">
                    bindings: [{{ q.bindings.join(', ') }}]
                </div>
            </li>
        </ol>
    </div>
</template>
