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
                class="group flex w-full items-center gap-1.5 px-1 text-left hover:bg-accent-soft"
                @click="open = !open"
            >
                <svg
                    class="h-2.5 w-2.5 shrink-0 text-ink-3 transition-transform duration-150 group-hover:text-accent"
                    :class="open ? 'rotate-90' : ''"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path d="M7 5l6 5-6 5V5z" />
                </svg>
                <span v-if="nodeKey !== null" class="text-key">{{ nodeKey }}:</span>
                <span class="text-ink-2">{{ summary }}</span>
            </button>

            <div v-if="open" class="ml-2.5 border-l border-rule pl-2.5">
                <div v-if="!node.entries?.length" class="nx-cap px-1 py-0.5">empty</div>
                <TreeNode
                    v-for="(entry, i) in node.entries"
                    :key="i"
                    :node-key="entry.key"
                    :node="entry.node"
                    :depth="depth + 1"
                />
                <div v-if="node.truncated" class="nx-cap px-1 py-0.5">
                    … more entries hidden (capped)
                </div>
            </div>
        </template>

        <!-- Leaf row -->
        <div v-else class="flex items-baseline gap-1.5 px-1">
            <span v-if="nodeKey !== null" class="shrink-0 text-key">{{ nodeKey }}:</span>

            <span v-if="node.kind === 'null'" class="italic text-ink-3">null</span>
            <span v-else-if="node.kind === 'bool'" class="text-bool">{{ node.value ? 'true' : 'false' }}</span>
            <span v-else-if="node.kind === 'number'" class="tabular-nums text-num">{{ node.value }}</span>
            <span v-else-if="node.kind === 'string'" class="break-all text-str">
                "{{ node.value }}"<span v-if="node.truncated" class="text-ink-3"> … ({{ node.length }} chars)</span>
            </span>
            <span v-else-if="node.collapsed" class="text-ink-3">{{ node.preview }} <span class="italic">(too deep)</span></span>
            <span v-else class="text-ink-2">{{ node.preview ?? node.kind }}</span>
        </div>
    </div>
</template>
