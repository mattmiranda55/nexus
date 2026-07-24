<script setup>
// A4 — shows what the last run wrote to the project log, inline under the
// output. Reuses the log parser/level styling so a run that quietly logged a
// warning or exception surfaces right next to its result.
import { computed, ref } from 'vue';
import { buildParsedLogs, levelStyle } from '../lib/logParser.js';

const props = defineProps({
    text: { type: String, default: '' },
});

const open = ref(false);
const entries = computed(() => buildParsedLogs(props.text));

const SEVERE = ['emergency', 'alert', 'critical', 'error'];
const errorCount = computed(() => entries.value.filter((e) => SEVERE.includes(e.level)).length);
const hasErrors = computed(() => errorCount.value > 0);
</script>

<template>
    <div
        v-if="entries.length"
        class="shrink-0 border-t text-xs"
        :class="hasErrors ? 'border-red-300 bg-red-50 dark:border-red-900/60 dark:bg-red-950/30' : 'border-neutral-200 bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-900'"
    >
        <button type="button" class="flex w-full items-center gap-2 px-3 py-1.5 text-left" @click="open = !open">
            <span :class="hasErrors ? 'text-red-500' : 'text-neutral-400'">⚑</span>
            <span :class="hasErrors ? 'font-medium text-red-700 dark:text-red-300' : 'text-neutral-600 dark:text-neutral-300'">
                This run logged {{ entries.length }} {{ entries.length === 1 ? 'entry' : 'entries' }}
                <span v-if="hasErrors">· {{ errorCount }} error{{ errorCount === 1 ? '' : 's' }}</span>
            </span>
            <span class="ml-auto text-neutral-400">{{ open ? '▾' : '▸' }}</span>
        </button>

        <div v-if="open" class="max-h-40 overflow-auto border-t border-black/5 px-3 py-1 font-mono dark:border-white/5">
            <div v-for="(entry, i) in entries" :key="i" class="flex items-start gap-2 py-0.5">
                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full" :class="levelStyle(entry.level).dot"></span>
                <span class="shrink-0 font-semibold uppercase" :class="levelStyle(entry.level).text">
                    {{ entry.originalLevel || entry.level }}
                </span>
                <span class="break-words text-neutral-700 dark:text-neutral-300">{{ entry.message }}</span>
            </div>
        </div>
    </div>
</template>
