<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Sidebar from '../Components/Sidebar.vue';
import SettingsModal from '../Components/SettingsModal.vue';
import HistoryModal from '../Components/HistoryModal.vue';
import Toolbar from '../Components/Toolbar.vue';
import Output from '../Components/Output.vue';
import StatusBar from '../Components/StatusBar.vue';
import { postJson } from '../lib/http.js';

// Lazy-loaded so the CodeMirror editor (the bundle's heaviest dependency) and
// the log viewer don't block the app shell's first paint.
const Editor = defineAsyncComponent(() => import('../Components/Editor.vue'));
const LogViewer = defineAsyncComponent(() => import('../Components/LogViewer.vue'));
const MailInbox = defineAsyncComponent(() => import('../Components/MailInbox.vue'));

const props = defineProps({
    projects: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({ theme: 'dark', phpPath: null }) },
    activeProjectId: { type: [Number, null], default: null },
});

const page = usePage();
const settingsOpen = ref(false);
const historyOpen = ref(false);
const activeTab = ref('tinker'); // tinker | logs | mail
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

// Restore = load into the buffer and show it; running stays a deliberate ⌘↵.
function restoreRun(run) {
    code.value = run.code;
    activeTab.value = 'tinker';
    historyOpen.value = false;
}
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
                @history="historyOpen = true"
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

                <MailInbox v-else :active-project="activeProject" />
            </div>

            <StatusBar :active-project="activeProject" :running="running" :theme="settings.theme" />
        </main>

        <SettingsModal
            v-if="settingsOpen"
            :settings="settings"
            @close="settingsOpen = false"
        />

        <HistoryModal
            v-if="historyOpen"
            @close="historyOpen = false"
            @restore="restoreRun"
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
