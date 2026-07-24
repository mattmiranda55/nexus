<script setup>
// ⌘K command palette — the hub's switchboard. Receives a flat list of
// commands ({ id, group, label, hint?, keywords? }) built by Console.vue from
// live state, ranks them with lib/fuzzy, and emits the chosen one. The parent
// owns execution; this component owns search + keyboard UX.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { fuzzyFilter } from '../lib/fuzzy.js';

const props = defineProps({
    commands: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'select']);

const query = ref('');
const selected = ref(0);
const input = ref(null);
const list = ref(null);

const results = computed(() =>
    fuzzyFilter(query.value.trim(), props.commands, (c) =>
        `${c.group} ${c.label} ${c.keywords ?? ''}`,
    ),
);

// Show a group header whenever the group changes between adjacent rows —
// meaningful order (grouped) when browsing, ranked order when searching.
const rows = computed(() =>
    results.value.map((command, i) => ({
        command,
        header: i === 0 || results.value[i - 1].group !== command.group ? command.group : null,
    })),
);

watch(results, () => {
    selected.value = 0;
});

function move(delta) {
    const max = results.value.length - 1;
    if (max < 0) return;
    selected.value = Math.min(max, Math.max(0, selected.value + delta));
    nextTick(() => {
        list.value?.querySelector('[data-selected="true"]')?.scrollIntoView({ block: 'nearest' });
    });
}

function choose(command) {
    if (command) emit('select', command);
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(results.value[selected.value]);
    }
}

onMounted(() => {
    input.value?.focus();
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/30" @click="emit('close')">
        <div
            class="mx-auto mt-[12vh] w-[36rem] max-w-[90vw] overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-2xl dark:border-neutral-700 dark:bg-neutral-900"
            @click.stop
        >
            <input
                ref="input"
                v-model="query"
                type="text"
                placeholder="Type a command, project, snippet…"
                class="w-full border-b border-neutral-200 bg-transparent px-4 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none dark:border-neutral-700 dark:text-neutral-100"
                spellcheck="false"
            />

            <div ref="list" class="max-h-[50vh] overflow-y-auto py-1">
                <template v-for="(row, i) in rows" :key="row.command.id">
                    <div
                        v-if="row.header"
                        class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-neutral-400"
                    >
                        {{ row.header }}
                    </div>
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-1.5 text-left text-sm"
                        :class="i === selected
                            ? 'bg-emerald-600 text-white'
                            : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                        :data-selected="i === selected"
                        @click="choose(row.command)"
                        @mousemove="selected = i"
                    >
                        <span class="truncate">{{ row.command.label }}</span>
                        <span
                            v-if="row.command.hint"
                            class="ml-auto shrink-0 truncate font-mono text-[11px]"
                            :class="i === selected ? 'text-emerald-100' : 'text-neutral-400'"
                        >{{ row.command.hint }}</span>
                    </button>
                </template>

                <div v-if="!rows.length" class="px-4 py-6 text-center text-sm text-neutral-400">
                    No matching commands
                </div>
            </div>

            <div class="flex items-center gap-3 border-t border-neutral-200 px-4 py-1.5 text-[10px] text-neutral-400 dark:border-neutral-700">
                <span>↑↓ navigate</span>
                <span>↵ select</span>
                <span>esc close</span>
            </div>
        </div>
    </div>
</template>
