<script setup>
defineProps({
    output: { type: String, default: '' },
    running: { type: Boolean, default: false },
});
</script>

<template>
    <div class="h-full overflow-auto bg-neutral-50 p-3 font-mono text-xs dark:bg-neutral-950">
        <div v-if="running" class="text-neutral-400">Running…</div>
        <pre v-else-if="output" class="whitespace-pre-wrap break-words text-neutral-800 dark:text-neutral-200">{{ output }}</pre>
        <div v-else class="text-neutral-400">Output will appear here. Press ⌘↵ to run.</div>
    </div>
</template>
