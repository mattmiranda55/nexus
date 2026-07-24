<script setup>
// The Laravel workbench: introspection panels scoped to the active project.
// Each sub-panel fetches on demand and renders through the shared table view.
// The active panel is a model so the command palette can deep-link into one.
import RoutesPanel from './RoutesPanel.vue';
import ModelsPanel from './ModelsPanel.vue';
import MigrationsPanel from './MigrationsPanel.vue';
import DatabasePanel from './DatabasePanel.vue';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const emit = defineEmits(['run-code']);

const panel = defineModel('panel', { type: String, default: 'models' });
const PANELS = [
    { key: 'models', label: 'Models' },
    { key: 'database', label: 'Database' },
    { key: 'routes', label: 'Routes' },
    { key: 'migrations', label: 'Migrations' },
];
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex items-center gap-1 border-b border-neutral-200 px-2 py-1.5 dark:border-neutral-800">
            <button
                v-for="p in PANELS"
                :key="p.key"
                type="button"
                class="rounded px-2.5 py-1 text-xs"
                :class="panel === p.key ? 'bg-neutral-200 font-medium text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'"
                @click="panel = p.key"
            >
                {{ p.label }}
            </button>
        </div>

        <div v-if="!activeProject" class="flex flex-1 items-center justify-center text-sm text-neutral-400">
            Select a project to use the workbench.
        </div>

        <div v-else class="min-h-0 flex-1">
            <ModelsPanel v-if="panel === 'models'" :active-project="activeProject" @run-code="emit('run-code', $event)" />
            <DatabasePanel v-else-if="panel === 'database'" :active-project="activeProject" @run-code="emit('run-code', $event)" />
            <RoutesPanel v-else-if="panel === 'routes'" :active-project="activeProject" />
            <MigrationsPanel v-else :active-project="activeProject" />
        </div>
    </div>
</template>
