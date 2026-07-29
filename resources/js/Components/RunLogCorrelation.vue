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
        class="shrink-0 border-t"
        :class="hasErrors ? 'border-err/50 bg-err-soft' : 'border-rule bg-raised'"
    >
        <button type="button" class="flex w-full items-center gap-2.5 px-3 py-1.5 text-left" @click="open = !open">
            <span :class="hasErrors ? 'text-err' : 'text-ink-3'">⚑</span>
            <span class="nx-cap" :class="hasErrors ? 'text-err' : 'text-ink-2'">
                Run logged {{ entries.length }} {{ entries.length === 1 ? 'entry' : 'entries' }}
                <span v-if="hasErrors">· {{ errorCount }} error{{ errorCount === 1 ? '' : 's' }}</span>
            </span>
            <span class="nx-leader"></span>
            <span class="text-[9px] text-ink-3">{{ open ? '▾' : '▸' }}</span>
        </button>

        <div v-if="open" class="max-h-40 overflow-auto border-t border-rule px-3 py-1 font-mono text-[11px]">
            <div v-for="(entry, i) in entries" :key="i" class="flex items-start gap-2 py-0.5">
                <span class="mt-1 h-1.5 w-1.5 shrink-0" :class="levelStyle(entry.level).dot"></span>
                <span
                    class="shrink-0 text-[10px] uppercase tracking-[0.1em]"
                    :class="levelStyle(entry.level).text"
                >{{ entry.originalLevel || entry.level }}</span>
                <span class="break-words text-ink-2">{{ entry.message }}</span>
            </div>
        </div>
    </div>
</template>
