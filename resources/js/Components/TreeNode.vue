<script setup>
// One node of the structured tree. Recurses into itself for children, so deep
// arrays/objects lazy-render only the branches the user actually expands.
import { computed, ref } from 'vue';

const props = defineProps({
    nodeKey: { type: [String, null], default: null },
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const CONTAINER = ['list', 'assoc', 'collection', 'model', 'object'];
const isContainer = computed(() => CONTAINER.includes(props.node.kind) && !props.node.collapsed);
const open = ref(props.depth < 1); // top level starts expanded

const shortClass = (fqcn) => (fqcn ? fqcn.split('\\').pop() : null);

// The bracketed summary shown on a container row, e.g. "User {6}" or "array [3]".
const summary = computed(() => {
    const n = props.node;
    const cls = shortClass(n.class);
    if (n.kind === 'collection' || n.kind === 'list') {
        return `${cls ?? 'array'} [${n.count}]`;
    }
    return `${cls ?? 'array'} {${n.count}}`;
});
</script>

<template>
    <div class="leading-relaxed">
        <!-- Container row: a clickable toggle -->
        <template v-if="isContainer">
            <button
                type="button"
                class="group flex w-full items-center gap-1 rounded px-1 text-left hover:bg-neutral-100 dark:hover:bg-neutral-900"
                @click="open = !open"
            >
                <svg
                    class="h-3 w-3 shrink-0 text-neutral-400 transition-transform"
                    :class="open ? 'rotate-90' : ''"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path d="M7 5l6 5-6 5V5z" />
                </svg>
                <span v-if="nodeKey !== null" class="text-sky-500 dark:text-sky-400">{{ nodeKey }}:</span>
                <span class="text-neutral-500">{{ summary }}</span>
            </button>

            <div v-if="open" class="ml-3 border-l border-neutral-200 pl-2 dark:border-neutral-800">
                <div v-if="!node.entries?.length" class="px-1 text-neutral-400">empty</div>
                <TreeNode
                    v-for="(entry, i) in node.entries"
                    :key="i"
                    :node-key="entry.key"
                    :node="entry.node"
                    :depth="depth + 1"
                />
                <div v-if="node.truncated" class="px-1 text-neutral-400">
                    … more entries hidden (capped)
                </div>
            </div>
        </template>

        <!-- Leaf row -->
        <div v-else class="flex items-baseline gap-1 px-1">
            <span v-if="nodeKey !== null" class="shrink-0 text-sky-500 dark:text-sky-400">{{ nodeKey }}:</span>

            <span v-if="node.kind === 'null'" class="italic text-neutral-400">null</span>
            <span v-else-if="node.kind === 'bool'" class="text-purple-500 dark:text-purple-400">{{ node.value ? 'true' : 'false' }}</span>
            <span v-else-if="node.kind === 'number'" class="text-amber-600 dark:text-amber-400">{{ node.value }}</span>
            <span v-else-if="node.kind === 'string'" class="break-all text-emerald-600 dark:text-emerald-400">
                "{{ node.value }}"<span v-if="node.truncated" class="text-neutral-400"> … ({{ node.length }} chars)</span>
            </span>
            <span v-else-if="node.collapsed" class="text-neutral-400">{{ node.preview }} <span class="italic">(too deep)</span></span>
            <span v-else class="text-neutral-500">{{ node.preview ?? node.kind }}</span>
        </div>
    </div>
</template>
