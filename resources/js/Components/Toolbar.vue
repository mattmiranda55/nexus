<script setup>
defineProps({
    running: { type: Boolean, default: false },
    activeTab: { type: String, default: 'tinker' },
    hasProject: { type: Boolean, default: false },
    layout: { type: String, default: 'vertical' },
});

defineEmits(['run', 'update:activeTab', 'update:layout']);
</script>

<template>
    <div class="flex items-center gap-3 border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
        <button
            type="button"
            class="flex items-center gap-1.5 rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="running || !hasProject"
            :title="hasProject ? 'Run (⌘↵)' : 'Select a project first'"
            @click="$emit('run')"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                <path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" />
            </svg>
            {{ running ? 'Running…' : 'Run' }}
        </button>

        <div class="flex rounded-md bg-neutral-100 p-0.5 text-sm dark:bg-neutral-800">
            <button
                type="button"
                class="rounded px-3 py-1"
                :class="activeTab === 'tinker' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                @click="$emit('update:activeTab', 'tinker')"
            >
                Tinker
            </button>
            <button
                type="button"
                class="rounded px-3 py-1"
                :class="activeTab === 'logs' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                @click="$emit('update:activeTab', 'logs')"
            >
                Logs
            </button>
            <button
                type="button"
                class="rounded px-3 py-1"
                :class="activeTab === 'workbench' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                @click="$emit('update:activeTab', 'workbench')"
            >
                Workbench
            </button>
            <button
                type="button"
                class="rounded px-3 py-1"
                :class="activeTab === 'mail' ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500'"
                @click="$emit('update:activeTab', 'mail')"
            >
                Mail
            </button>
        </div>

        <button
            v-if="activeTab === 'tinker'"
            type="button"
            class="ml-auto rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
            :title="layout === 'vertical' ? 'Switch to side-by-side' : 'Switch to stacked'"
            @click="$emit('update:layout', layout === 'vertical' ? 'horizontal' : 'vertical')"
        >
            <svg v-if="layout === 'vertical'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                <path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h4A1.5 1.5 0 0 1 10 4.5v11A1.5 1.5 0 0 1 8.5 17h-4A1.5 1.5 0 0 1 3 15.5v-11ZM11.5 3A1.5 1.5 0 0 0 10 4.5v11A1.5 1.5 0 0 0 11.5 17h4a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 15.5 3h-4Z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                <path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h11A1.5 1.5 0 0 1 17 4.5v4A1.5 1.5 0 0 1 15.5 10h-11A1.5 1.5 0 0 1 3 8.5v-4ZM4.5 11A1.5 1.5 0 0 0 3 12.5v3A1.5 1.5 0 0 0 4.5 17h11a1.5 1.5 0 0 0 1.5-1.5v-3a1.5 1.5 0 0 0-1.5-1.5h-11Z" />
            </svg>
        </button>

        <span class="text-xs text-neutral-400" :class="activeTab === 'tinker' ? '' : 'ml-auto'">⌘↵ to run</span>
    </div>
</template>
