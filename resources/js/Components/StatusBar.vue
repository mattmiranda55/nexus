<script setup>
defineProps({
    activeProject: { type: Object, default: null },
    running: { type: Boolean, default: false },
    theme: { type: String, default: 'dark' },
});
</script>

<template>
    <!-- Ticker strip: everything here is chrome, so it's all mono micro-caps. -->
    <footer class="flex h-6 shrink-0 items-center gap-2.5 border-t border-rule bg-paper px-3">
        <span class="nx-cap shrink-0" :class="activeProject ? 'text-ink-2' : ''">
            {{ activeProject ? activeProject.name : 'No project' }}
        </span>

        <span v-if="activeProject" class="nx-cap min-w-0 truncate normal-case tracking-normal" :title="activeProject.path">
            {{ activeProject.path }}
        </span>

        <span class="nx-leader"></span>

        <span class="flex shrink-0 items-center gap-1.5">
            <span
                class="h-1.5 w-1.5"
                :class="running ? 'bg-accent nx-blink' : 'bg-ok'"
            ></span>
            <span class="nx-cap" :class="running ? 'text-accent' : ''">{{ running ? 'Running' : 'Ready' }}</span>
        </span>

        <span class="nx-cap shrink-0 text-ink-3">·</span>

        <span class="nx-cap shrink-0">{{ theme }}</span>
    </footer>
</template>
