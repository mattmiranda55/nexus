<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Sidebar from '../Components/Sidebar.vue';
import SettingsModal from '../Components/SettingsModal.vue';
import HistoryModal from '../Components/HistoryModal.vue';
import Toolbar from '../Components/Toolbar.vue';
import Output from '../Components/Output.vue';
import StatusBar from '../Components/StatusBar.vue';
import { deleteJson, getJson, postJson } from '../lib/http.js';
import { onChildProcessExit, onChildProcessMessage, onNotificationClicked } from '../lib/nativeEvents.js';
import { watchTinkerRun } from '../lib/tinkerRun.js';

// Lazy-loaded so the CodeMirror editor (the bundle's heaviest dependency) and
// the log viewer don't block the app shell's first paint.
const Editor = defineAsyncComponent(() => import('../Components/Editor.vue'));
const LogViewer = defineAsyncComponent(() => import('../Components/LogViewer.vue'));
const MailInbox = defineAsyncComponent(() => import('../Components/MailInbox.vue'));

const props = defineProps({
    projects: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({ theme: 'dark', phpPath: null }) },
    platform: { type: String, default: 'Darwin' }, // PHP_OS_FAMILY
    activeProjectId: { type: [Number, null], default: null },
});

const page = usePage();
const settingsOpen = ref(false);
const historyOpen = ref(false);
// Mail isn't per project (several apps usually share one Mailpit), so it's
// its own destination in the sidebar rather than a tab of the project view.
const view = ref('project'); // project | mail
const activeTab = ref('tinker'); // tinker | logs (within the project view)
// The inbox is mounted from launch and stays alive (hidden) so its live
// connection keeps the unread badge current and new mail can notify even
// before Mail was ever opened.
const mailInbox = ref(null);
const unread = ref(0);
const layout = ref('vertical'); // vertical (stacked) | horizontal (side-by-side)
const running = ref(false);
// Set while a background run can be stopped: { id, watcher }. Inline runs
// (outside the desktop app) block the server, so they can't be.
const stoppable = ref(null);

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
    view.value = 'project';
    activeTab.value = 'tinker';
    // In the desktop app the run happens in a background worker (202), so the
    // rest of the UI keeps responding; elsewhere it comes back inline (200).
    const id = crypto.randomUUID();
    const watcher = watchTinkerRun(id, {
        fetchResult: (runId, exited) => getJson(`/tinker/${runId}${exited ? '?exited=1' : ''}`),
        onMessage: onChildProcessMessage,
        onExit: onChildProcessExit,
    });
    try {
        let { ok, status, data } = await postJson('/tinker', { code: code.value, id });
        if (status === 202) {
            stoppable.value = { id, watcher };
            ({ ok, status, data } = await watcher.result());
        }
        outputs.value[key] = {
            envelope: data?.envelope ?? null,
            raw: data?.raw ?? data?.output ?? (ok ? '(no output)' : requestError(status, data)),
            logged: data?.loggedDuringRun ?? null,
        };
    } catch (e) {
        outputs.value[key] = { envelope: null, raw: 'Error: ' + e.message, logged: null };
    } finally {
        watcher.stop();
        stoppable.value = null;
        running.value = false;
    }
}

// Kills the worker; the pending runTinker() then settles with the stop
// endpoint's response (or the real result, if it finished just before).
function stopTinker() {
    const run = stoppable.value;
    if (!run) return;
    stoppable.value = null;
    run.watcher.cancel(() => deleteJson(`/tinker/${run.id}`));
}

// A failed request with no tinker output of its own: say what went wrong
// rather than showing a blank result.
function requestError(status, data) {
    if (status === 419) return 'Error: the session expired. Reload the window (Cmd/Ctrl+R) and run again.';
    const message = data?.message ?? data?.error;
    return `Error: the run request failed (HTTP ${status})${message ? ` — ${message}` : ''}`;
}

function showMail() {
    view.value = 'mail';
}

// A click on a new-mail notification: AppServiceProvider brings the window
// forward; here, open the message it was about.
const stopNotificationClicks = onNotificationClicked((reference) => {
    if (!reference.startsWith('nexus-mail:')) return;
    showMail();
    mailInbox.value?.select(reference.slice('nexus-mail:'.length));
});
onBeforeUnmount(stopNotificationClicks);

// Restore = load into the buffer and show it; running stays a deliberate ⌘↵.
function restoreRun(run) {
    code.value = run.code;
    view.value = 'project';
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
            :mail-active="view === 'mail'"
            :unread="unread"
            @open-settings="settingsOpen = true"
            @open-mail="showMail"
            @show-project="view = 'project'"
        />

        <main class="flex min-w-0 flex-1 flex-col">
            <Toolbar
                v-show="view === 'project'"
                :running="running"
                :can-stop="!!stoppable"
                v-model:active-tab="activeTab"
                v-model:layout="layout"
                :has-project="!!activeProject"
                :platform="platform"
                @run="runTinker"
                @stop="stopTinker"
                @history="historyOpen = true"
            />

            <!-- v-show, not v-if: a look at the inbox shouldn't tear down the
                 editor (cursor, undo history) or restart the log tail. -->
            <div v-show="view === 'project'" class="flex min-h-0 flex-1 flex-col">
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

                <LogViewer v-else :active-project="activeProject" :settings="settings" />
            </div>

            <div v-show="view === 'mail'" class="flex min-h-0 flex-1 flex-col">
                <MailInbox
                    ref="mailInbox"
                    :notify="settings.notifyMail ?? true"
                    :settings-key="[settings.mailUrl, settings.mailPin, settings.mailpitMode].join('|')"
                    @unread="unread = $event"
                    @open-settings="settingsOpen = true"
                />
            </div>

            <StatusBar :view="view" :active-project="activeProject" :running="running" :theme="settings.theme" />
        </main>

        <SettingsModal
            v-if="settingsOpen"
            :settings="settings"
            :platform="platform"
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
