<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Sidebar from '../Components/Sidebar.vue';
import SettingsModal from '../Components/SettingsModal.vue';
import SaveSnippetModal from '../Components/SaveSnippetModal.vue';
import HistoryModal from '../Components/HistoryModal.vue';
import CommandPalette from '../Components/CommandPalette.vue';
import Toolbar from '../Components/Toolbar.vue';
import Output from '../Components/Output.vue';
import StatusBar from '../Components/StatusBar.vue';
import { getJson, postJson } from '../lib/http.js';

// Lazy-loaded so the CodeMirror editor (the bundle's heaviest dependency) and
// the log viewer don't block the app shell's first paint.
const Editor = defineAsyncComponent(() => import('../Components/Editor.vue'));
const LogViewer = defineAsyncComponent(() => import('../Components/LogViewer.vue'));
const Workbench = defineAsyncComponent(() => import('../Components/Workbench/Workbench.vue'));
const MailInbox = defineAsyncComponent(() => import('../Components/MailInbox.vue'));

const props = defineProps({
    projects: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({ theme: 'dark', phpPath: null }) },
    activeProjectId: { type: [Number, null], default: null },
});

const page = usePage();
const settingsOpen = ref(false);
const paletteOpen = ref(false);
const saveSnippetOpen = ref(false);
const savingSnippet = ref(false);
const historyOpen = ref(false);
const activeTab = ref('tinker');
const workbenchPanel = ref('models');
const layout = ref('vertical'); // vertical (stacked) | horizontal (side-by-side)
const running = ref(false);

const DEFAULT_CODE = "// Explore your app — Cmd/Ctrl+Enter to run\nUser::count();";

// Tinker context is per-project (like the log stream): each project keeps its
// own scratch buffer and last result, so switching projects visibly swaps what
// you're looking at instead of leaving the previous project's code/output up.
const buffers = ref({}); // projectId -> editor contents
const outputs = ref({}); // projectId -> { envelope, raw }

// Keys the buffer maps; falls back to a shared slot when no project is active.
function bufferKey(id) {
    return id ?? '_none';
}

const code = computed({
    get: () => buffers.value[bufferKey(props.activeProjectId)] ?? DEFAULT_CODE,
    set: (value) => {
        buffers.value[bufferKey(props.activeProjectId)] = value;
    },
});

const output = computed(
    () => outputs.value[bufferKey(props.activeProjectId)] ?? { envelope: null, raw: '', logged: null },
);

const activeProject = computed(
    () => props.projects.find((p) => p.id === props.activeProjectId) ?? null,
);

const isDark = computed(() => props.settings.theme !== 'light');

// Apply the persisted theme to <html> so Tailwind's dark: variants respond.
function applyTheme(theme) {
    document.documentElement.classList.toggle('dark', theme !== 'light');
}
applyTheme(props.settings.theme);
watch(() => props.settings.theme, applyTheme);

const flashError = computed(() => page.props.flash?.error);

// Workbench panels can hand a starter query to the editor: load it into the
// active project's buffer, switch to the Tinker tab, and run it.
function runCode(newCode) {
    if (!activeProject.value) return;
    code.value = newCode;
    activeTab.value = 'tinker';
    runTinker();
}

async function runTinker() {
    if (running.value || !activeProject.value) return;

    // Pin the target project so a mid-run project switch writes the result to
    // the project it actually ran against, not whatever is active on return.
    const key = bufferKey(props.activeProjectId);
    running.value = true;
    activeTab.value = 'tinker';
    try {
        const { data } = await postJson('/tinker', { code: code.value });
        outputs.value[key] = {
            envelope: data?.envelope ?? null,
            raw: data?.raw ?? data?.output ?? '(no output)',
            logged: data?.loggedDuringRun ?? null,
        };
    } catch (e) {
        outputs.value[key] = { envelope: null, raw: 'Error: ' + e.message, logged: null };
    } finally {
        running.value = false;
    }
}

// --- Snippet library -------------------------------------------------------

const snippets = ref([]);

async function loadSnippets() {
    const { ok, data } = await getJson('/snippets');
    snippets.value = ok ? data?.snippets ?? [] : [];
}

async function saveSnippet({ name, global }) {
    savingSnippet.value = true;
    try {
        const { ok } = await postJson('/snippets', { name, code: code.value, global });
        if (ok) {
            saveSnippetOpen.value = false;
            await loadSnippets();
        }
    } finally {
        savingSnippet.value = false;
    }
}

// Insert = load into the buffer and show it; running stays a deliberate ⌘↵.
function insertSnippet(snippet) {
    code.value = snippet.code;
    activeTab.value = 'tinker';
}

function restoreRun(run) {
    code.value = run.code;
    activeTab.value = 'tinker';
    historyOpen.value = false;
}

watch(() => props.activeProjectId, loadSnippets, { immediate: true });

// --- Command palette -------------------------------------------------------

// Recent runs are fetched when the palette opens so its History group is live.
const paletteRuns = ref([]);

async function openPalette() {
    paletteOpen.value = true;
    const { ok, data } = await getJson('/history');
    paletteRuns.value = ok ? (data?.runs ?? []).slice(0, 8) : [];
}

function toggleTheme() {
    router.patch('/settings', {
        theme: isDark.value ? 'light' : 'dark',
        phpPath: props.settings.phpPath ?? '',
        editor: props.settings.editor ?? 'phpstorm',
        notifyErrors: props.settings.notifyErrors ?? false,
    }, { preserveScroll: true, preserveState: true });
}

function goWorkbench(panel) {
    workbenchPanel.value = panel;
    activeTab.value = 'workbench';
}

const oneLine = (text, max = 60) => {
    const line = text.split('\n').find((l) => l.trim()) ?? '';
    return line.length > max ? line.slice(0, max) + '…' : line;
};

const paletteCommands = computed(() => {
    const commands = [];

    if (activeProject.value) {
        commands.push(
            { id: 'run', group: 'Actions', label: 'Run buffer', hint: '⌘↵', action: runTinker },
            { id: 'save-snippet', group: 'Actions', label: 'Save buffer as snippet…', action: () => { saveSnippetOpen.value = true; } },
            { id: 'history', group: 'Actions', label: 'Show run history', action: () => { historyOpen.value = true; } },
        );
    }
    commands.push(
        { id: 'layout', group: 'Actions', label: 'Toggle editor layout', keywords: 'split stacked side', action: () => { layout.value = layout.value === 'vertical' ? 'horizontal' : 'vertical'; } },
        { id: 'theme', group: 'Actions', label: `Switch to ${isDark.value ? 'light' : 'dark'} theme`, action: toggleTheme },
        { id: 'settings', group: 'Actions', label: 'Open settings', action: () => { settingsOpen.value = true; } },
        { id: 'add-project', group: 'Actions', label: 'Add Laravel project…', action: () => router.post('/projects', {}, { preserveScroll: true }) },
    );

    commands.push(
        { id: 'go-tinker', group: 'Go to', label: 'Tinker', action: () => { activeTab.value = 'tinker'; } },
        { id: 'go-logs', group: 'Go to', label: 'Logs', action: () => { activeTab.value = 'logs'; } },
        { id: 'go-wb-models', group: 'Go to', label: 'Workbench: Models', action: () => goWorkbench('models') },
        { id: 'go-wb-db', group: 'Go to', label: 'Workbench: Database', keywords: 'tables sql browse', action: () => goWorkbench('database') },
        { id: 'go-wb-routes', group: 'Go to', label: 'Workbench: Routes', action: () => goWorkbench('routes') },
        { id: 'go-wb-migrations', group: 'Go to', label: 'Workbench: Migrations', action: () => goWorkbench('migrations') },
        { id: 'go-mail', group: 'Go to', label: 'Mail', keywords: 'inbox mailpit', action: () => { activeTab.value = 'mail'; } },
    );

    for (const project of props.projects) {
        if (project.id === props.activeProjectId) continue;
        commands.push({
            id: `project-${project.id}`,
            group: 'Projects',
            label: `Switch to ${project.name}`,
            hint: project.path,
            action: () => router.post(`/projects/${project.id}/activate`, {}, { preserveScroll: true, preserveState: true }),
        });
    }

    for (const snippet of snippets.value) {
        commands.push({
            id: `snippet-${snippet.id}`,
            group: 'Snippets',
            label: snippet.name,
            hint: snippet.project_id === null ? 'global' : oneLine(snippet.code, 32),
            // First line only: fuzzy's length penalty would bury a long body.
            keywords: oneLine(snippet.code, 80),
            action: () => insertSnippet(snippet),
        });
    }

    for (const run of paletteRuns.value) {
        commands.push({
            id: `run-${run.id}`,
            group: 'Recent runs',
            label: oneLine(run.code),
            hint: `${run.duration_ms}ms`,
            action: () => restoreRun(run),
        });
    }

    return commands;
});

function executeCommand(command) {
    paletteOpen.value = false;
    command.action();
}

// ⌘K from anywhere — capture phase so it wins even while CodeMirror is focused.
function onGlobalKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        event.stopPropagation();
        if (paletteOpen.value) {
            paletteOpen.value = false;
        } else {
            openPalette();
        }
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown, true));
</script>

<template>
    <Head title="Nexus" />

    <div class="flex h-screen w-screen overflow-hidden bg-white text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <Sidebar
            :projects="projects"
            :active-project-id="activeProjectId"
            @open-settings="settingsOpen = true"
        />

        <main class="flex min-w-0 flex-1 flex-col">
            <Toolbar
                :running="running"
                v-model:active-tab="activeTab"
                v-model:layout="layout"
                :has-project="!!activeProject"
                @run="runTinker"
                @save-snippet="saveSnippetOpen = true"
                @history="historyOpen = true"
                @palette="openPalette"
            />

            <div class="flex min-h-0 flex-1 flex-col">
                <template v-if="activeTab === 'tinker'">
                    <div
                        class="flex min-h-0 flex-1"
                        :class="layout === 'vertical' ? 'flex-col' : 'flex-row'"
                    >
                        <div
                            class="min-h-0 min-w-0 flex-1 border-neutral-200 dark:border-neutral-800"
                            :class="layout === 'vertical' ? 'border-b' : 'border-r'"
                        >
                            <Editor v-model="code" :dark="isDark" @run="runTinker" />
                        </div>
                        <div
                            class="min-h-0 min-w-0"
                            :class="layout === 'vertical' ? 'h-2/5' : 'w-2/5'"
                        >
                            <Output :result="output" :running="running" />
                        </div>
                    </div>
                </template>

                <LogViewer v-else-if="activeTab === 'logs'" :active-project="activeProject" :settings="settings" />

                <Workbench
                    v-else-if="activeTab === 'workbench'"
                    v-model:panel="workbenchPanel"
                    :active-project="activeProject"
                    @run-code="runCode"
                />

                <MailInbox v-else :active-project="activeProject" />
            </div>

            <StatusBar :active-project="activeProject" :running="running" :theme="settings.theme" />
        </main>

        <SettingsModal
            v-if="settingsOpen"
            :settings="settings"
            @close="settingsOpen = false"
        />

        <SaveSnippetModal
            v-if="saveSnippetOpen"
            :saving="savingSnippet"
            @close="saveSnippetOpen = false"
            @save="saveSnippet"
        />

        <HistoryModal
            v-if="historyOpen"
            @close="historyOpen = false"
            @restore="restoreRun"
        />

        <CommandPalette
            v-if="paletteOpen"
            :commands="paletteCommands"
            @close="paletteOpen = false"
            @select="executeCommand"
        />

        <div
            v-if="flashError"
            class="pointer-events-none fixed inset-x-0 top-3 flex justify-center"
        >
            <div class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white shadow-lg">
                {{ flashError }}
            </div>
        </div>
    </div>
</template>
