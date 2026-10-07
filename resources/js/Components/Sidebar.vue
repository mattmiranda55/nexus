<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    activeProjectId: { type: [Number, null], default: null },
    mailActive: { type: Boolean, default: false },
    unread: { type: Number, default: 0 },
});

const emit = defineEmits(['open-settings', 'open-mail', 'show-project']);

const menu = ref({ open: false, x: 0, y: 0, project: null });

function addProject() {
    // Server-side: opens the native folder picker, validates, creates, activates.
    router.post('/projects', {}, { preserveScroll: true });
}

function activate(project) {
    // Clicking a project always brings its view back, even from Mail.
    emit('show-project');
    if (project.id === props.activeProjectId) return;
    router.post(`/projects/${project.id}/activate`, {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

// Rough menu footprint, so a right-click near the window edge doesn't open it
// half off-screen.
const MENU_WIDTH = 176;
const MENU_HEIGHT = 80;

function openMenu(event, project) {
    const x = Math.min(event.clientX, window.innerWidth - MENU_WIDTH);
    const y = Math.min(event.clientY, window.innerHeight - MENU_HEIGHT);
    menu.value = { open: true, x: Math.max(0, x), y: Math.max(0, y), project };
}

function closeMenu() {
    menu.value.open = false;
}

async function copyPath() {
    const project = menu.value.project;
    closeMenu();
    if (!project) return;
    try {
        await navigator.clipboard.writeText(project.path);
    } catch {
        // Clipboard access can be refused; nothing useful to do about it here.
    }
}

function removeProject() {
    const project = menu.value.project;
    closeMenu();
    // Removing also deletes the project's run history (cascade), so confirm.
    if (project && window.confirm(`Remove "${project.name}" from Nexus? Its run history will be deleted. Files on disk are not touched.`)) {
        router.delete(`/projects/${project.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <aside class="flex w-60 shrink-0 flex-col border-r border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900">
        <div class="flex items-center justify-between px-3 py-3">
            <span class="text-sm font-semibold tracking-tight">Nexus</span>
            <button
                type="button"
                class="rounded p-1 text-neutral-500 hover:bg-neutral-200 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                title="Settings"
                @click="$emit('open-settings')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                    <path fill-rule="evenodd" d="M8.34 1.804A1 1 0 0 1 9.32 1h1.36a1 1 0 0 1 .98.804l.295 1.473c.497.144.97.342 1.409.588l1.25-.834a1 1 0 0 1 1.262.125l.962.962a1 1 0 0 1 .125 1.262l-.834 1.25c.246.44.444.912.588 1.41l1.473.294a1 1 0 0 1 .804.98v1.36a1 1 0 0 1-.804.98l-1.473.295a7.014 7.014 0 0 1-.588 1.409l.834 1.25a1 1 0 0 1-.125 1.262l-.962.962a1 1 0 0 1-1.262.125l-1.25-.834c-.44.246-.912.444-1.41.588l-.294 1.473a1 1 0 0 1-.98.804H9.32a1 1 0 0 1-.98-.804l-.295-1.473a7.014 7.014 0 0 1-1.409-.588l-1.25.834a1 1 0 0 1-1.262-.125l-.962-.962a1 1 0 0 1-.125-1.262l.834-1.25a7.014 7.014 0 0 1-.588-1.41l-1.473-.294A1 1 0 0 1 1 10.68V9.32a1 1 0 0 1 .804-.98l1.473-.295c.144-.497.342-.97.588-1.409l-.834-1.25a1 1 0 0 1 .125-1.262l.962-.962A1 1 0 0 1 5.38 2.84l1.25.834c.44-.246.912-.444 1.41-.588l.294-1.473ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <div class="px-2">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-neutral-200 dark:hover:bg-neutral-800"
                :class="mailActive
                    ? 'bg-neutral-200 font-medium dark:bg-neutral-800'
                    : 'text-neutral-700 dark:text-neutral-300'"
                @click="$emit('open-mail')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-neutral-500">
                    <path d="M3 4a2 2 0 0 0-2 2v1.161l8.441 4.221a1.25 1.25 0 0 0 1.118 0L19 7.162V6a2 2 0 0 0-2-2H3Z" />
                    <path d="m19 8.839-7.77 3.885a2.75 2.75 0 0 1-2.46 0L1 8.839V14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.839Z" />
                </svg>
                Mail
                <span
                    v-if="unread"
                    class="ml-auto rounded-full bg-sky-100 px-1.5 text-[10px] font-medium text-sky-700 dark:bg-sky-900/60 dark:text-sky-300"
                    :title="`${unread} unread`"
                >{{ unread > 99 ? '99+' : unread }}</span>
            </button>
        </div>

        <div class="px-4 pb-1 pt-4 text-[10px] font-medium uppercase tracking-wider text-neutral-400">Projects</div>

        <div class="flex-1 overflow-y-auto px-2">
            <ul v-if="projects.length" class="space-y-0.5">
                <li v-for="project in projects" :key="project.id">
                    <button
                        type="button"
                        class="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-neutral-200 dark:hover:bg-neutral-800"
                        :class="project.id !== activeProjectId
                            ? 'text-neutral-700 dark:text-neutral-300'
                            : mailActive
                                ? 'font-medium'
                                : 'bg-neutral-200 font-medium dark:bg-neutral-800'"
                        @click="activate(project)"
                        @contextmenu.prevent="openMenu($event, project)"
                    >
                        <span class="block truncate">{{ project.name }}</span>
                        <span class="block truncate font-mono text-[10px] text-neutral-400">{{ project.path }}</span>
                    </button>
                </li>
            </ul>
            <p v-else class="px-2 py-4 text-center text-xs text-neutral-500">
                No projects yet.
            </p>
        </div>

        <div class="p-2">
            <button
                type="button"
                class="w-full rounded bg-neutral-800 px-2 py-1.5 text-sm text-white hover:bg-neutral-700 dark:bg-neutral-700 dark:hover:bg-neutral-600"
                @click="addProject"
            >
                + Add Laravel project
            </button>
        </div>
    </aside>

    <!-- Right-click context menu -->
    <template v-if="menu.open">
        <div class="fixed inset-0 z-40" @click="closeMenu" @contextmenu.prevent="closeMenu"></div>
        <div
            class="fixed z-50 min-w-40 rounded-md border border-neutral-200 bg-white py-1 text-sm shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
            :style="{ top: menu.y + 'px', left: menu.x + 'px' }"
        >
            <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-100 dark:hover:bg-neutral-700" @click="copyPath">
                Copy path
            </button>
            <button type="button" class="block w-full px-3 py-1.5 text-left text-red-600 hover:bg-neutral-100 dark:text-red-400 dark:hover:bg-neutral-700" @click="removeProject">
                Remove project
            </button>
        </div>
    </template>
</template>
