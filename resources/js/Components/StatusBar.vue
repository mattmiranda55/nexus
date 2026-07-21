<script setup>
defineProps({
    activeProject: { type: Object, default: null },
    running: { type: Boolean, default: false },
    theme: { type: String, default: 'dark' },
});
</script>

<template>
    <footer class="flex items-center gap-3 border-t border-neutral-200 px-3 py-1 text-[11px] text-neutral-500 dark:border-neutral-800">
        <span class="truncate">{{ activeProject ? activeProject.name : 'No project' }}</span>

        <span class="flex items-center gap-1">
            <span
                class="h-1.5 w-1.5 rounded-full"
                :class="running ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500'"
            ></span>
            {{ running ? 'Running' : 'Ready' }}
        </span>

        <span class="ml-auto capitalize">{{ theme }}</span>
    </footer>
</template>
