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
    <div class="h-full overflow-auto">
        <!-- Tally strip -->
        <div class="flex items-center gap-2.5 border-b border-rule px-3 py-1.5">
            <span class="nx-cap tabular-nums">{{ queries.length }} quer{{ queries.length === 1 ? 'y' : 'ies' }}</span>
            <span class="nx-leader"></span>
            <span class="nx-cap tabular-nums text-num">{{ totalTime }} ms</span>
        </div>

        <div v-if="suspects.length" class="border-b border-warn/40 bg-warn-soft px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="text-warn">⚠</span>
                <span class="nx-cap text-warn">Possible N+1 · {{ suspects.length }}</span>
            </div>
            <div
                v-for="s in suspects"
                :key="s.shape"
                class="mt-1 truncate font-mono text-[11px] text-ink-2"
                :title="s.shape"
            >
                <span class="text-warn">×{{ s.n }}</span> {{ s.shape }}
            </div>
        </div>

        <ol>
            <li
                v-for="(q, i) in queries"
                :key="i"
                class="border-b border-rule px-3 py-2 last:border-0 hover:bg-raised/60"
            >
                <div class="flex items-start gap-2.5 font-mono text-[11.5px]">
                    <span class="w-5 shrink-0 text-right tabular-nums text-accent">{{ i + 1 }}</span>
                    <code class="flex-1 whitespace-pre-wrap break-words leading-relaxed text-ink">{{ q.sql }}</code>
                    <span
                        v-if="shapeCounts[normalize(q.sql)] > N1_THRESHOLD"
                        class="shrink-0 border border-warn/50 px-1 text-[10px] text-warn"
                        title="Same query shape repeated"
                    >×{{ shapeCounts[normalize(q.sql)] }}</span>
                    <span
                        v-if="q.time !== null && q.time !== undefined"
                        class="shrink-0 tabular-nums text-[10px] text-ink-3"
                    >{{ q.time }}ms</span>
                </div>
                <div v-if="q.bindings?.length" class="ml-[1.9rem] mt-1 font-mono text-[10.5px] text-ink-3">
                    <span class="nx-cap">bind</span> [{{ q.bindings.join(', ') }}]
                </div>
            </li>
        </ol>
    </div>
</template>
