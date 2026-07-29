<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    activeProjectId: { type: [Number, null], default: null },
});

defineEmits(['open-settings']);

const menu = ref({ open: false, x: 0, y: 0, project: null });

function addProject() {
    // Server-side: opens the native folder picker, validates, creates, activates.
    router.post('/projects', {}, { preserveScroll: true });
}

function activate(project) {
    if (project.id === props.activeProjectId) return;
    router.post(`/projects/${project.id}/activate`, {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

function openMenu(event, project) {
    menu.value = { open: true, x: event.clientX, y: event.clientY, project };
}

function closeMenu() {
    menu.value.open = false;
}

async function copyPath() {
    if (menu.value.project) {
        await navigator.clipboard.writeText(menu.value.project.path);
    }
    closeMenu();
}

function removeProject() {
    const project = menu.value.project;
    closeMenu();
    if (project) {
        router.delete(`/projects/${project.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <aside class="flex w-[15rem] shrink-0 flex-col border-r border-rule bg-paper">
        <!-- Wordmark plate -->
        <div class="flex h-11 shrink-0 items-center gap-2.5 border-b border-rule px-3">
            <span class="h-2.5 w-2.5 shrink-0 rotate-45 bg-accent"></span>
            <span class="nx-wordmark flex-1">Nexus</span>
            <button
                type="button"
                class="nx-icon-btn"
                title="Settings"
                @click="$emit('open-settings')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                    <path fill-rule="evenodd" d="M8.34 1.804A1 1 0 0 1 9.32 1h1.36a1 1 0 0 1 .98.804l.295 1.473c.497.144.97.342 1.409.588l1.25-.834a1 1 0 0 1 1.262.125l.962.962a1 1 0 0 1 .125 1.262l-.834 1.25c.246.44.444.912.588 1.41l1.473.294a1 1 0 0 1 .804.98v1.36a1 1 0 0 1-.804.98l-1.473.295a7.014 7.014 0 0 1-.588 1.409l.834 1.25a1 1 0 0 1-.125 1.262l-.962.962a1 1 0 0 1-1.262.125l-1.25-.834c-.44.246-.912.444-1.41.588l-.294 1.473a1 1 0 0 1-.98.804H9.32a1 1 0 0 1-.98-.804l-.295-1.473a7.014 7.014 0 0 1-1.409-.588l-1.25.834a1 1 0 0 1-1.262-.125l-.962-.962a1 1 0 0 1-.125-1.262l.834-1.25a7.014 7.014 0 0 1-.588-1.41l-1.473-.294A1 1 0 0 1 1 10.68V9.32a1 1 0 0 1 .804-.98l1.473-.295c.144-.497.342-.97.588-1.409l-.834-1.25a1 1 0 0 1 .125-1.262l.962-.962A1 1 0 0 1 5.38 2.84l1.25.834c.44-.246.912-.444 1.41-.588l.294-1.473ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <!-- Section caption, ruled like a drawing legend -->
        <div class="flex shrink-0 items-center gap-2.5 px-3 pb-1.5 pt-3">
            <span class="nx-cap">Projects</span>
            <span class="nx-leader"></span>
            <span v-if="projects.length" class="nx-cap tabular-nums">{{ projects.length }}</span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <ul v-if="projects.length">
                <li v-for="project in projects" :key="project.id">
                    <button
                        type="button"
                        class="block w-full px-3 py-2 text-left transition-colors"
                        :class="project.id === activeProjectId
                            ? 'nx-marked bg-raised'
                            : 'hover:bg-raised/60'"
                        :title="project.path"
                        @click="activate(project)"
                        @contextmenu.prevent="openMenu($event, project)"
                    >
                        <span
                            class="block truncate text-[12.5px] leading-tight"
                            :class="project.id === activeProjectId ? 'font-medium text-ink' : 'text-ink-2'"
                        >{{ project.name }}</span>
                        <span class="mt-0.5 block truncate font-mono text-[10px] leading-tight text-ink-3">{{ project.path }}</span>
                    </button>
                </li>
            </ul>

            <div v-else class="mx-3 mt-1 border border-dashed border-rule-2 px-3 py-6 text-center">
                <p class="nx-cap">No projects</p>
                <p class="mt-2 text-[11px] leading-snug text-ink-3">
                    Add a Laravel directory to start a session.
                </p>
            </div>
        </div>

        <div class="shrink-0 border-t border-rule p-2">
            <button type="button" class="nx-btn w-full" @click="addProject">
                <span class="text-[13px] leading-none">+</span>
                Add project
            </button>
        </div>
    </aside>

    <!-- Right-click context menu -->
    <template v-if="menu.open">
        <div class="fixed inset-0 z-40" @click="closeMenu" @contextmenu.prevent="closeMenu"></div>
        <div
            class="nx-plate nx-fade fixed z-50 min-w-44 py-1"
            :style="{ top: menu.y + 'px', left: menu.x + 'px' }"
        >
            <div class="nx-cap truncate px-3 pb-1.5 pt-1">{{ menu.project?.name }}</div>
            <button
                type="button"
                class="block w-full px-3 py-1.5 text-left text-xs text-ink-2 hover:bg-raised hover:text-ink"
                @click="copyPath"
            >
                Copy path
            </button>
            <button
                type="button"
                class="block w-full px-3 py-1.5 text-left text-xs text-err hover:bg-err-soft"
                @click="removeProject"
            >
                Remove project
            </button>
        </div>
    </template>
</template>
