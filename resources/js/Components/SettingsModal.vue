<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { deleteJson, postJson } from '../lib/http.js';
import { onChildProcessExit } from '../lib/nativeEvents.js';

const props = defineProps({
    settings: { type: Object, required: true },
    platform: { type: String, default: 'Darwin' },
});

const emit = defineEmits(['close']);

const form = useForm({
    theme: props.settings.theme ?? 'dark',
    phpPath: props.settings.phpPath ?? '',
    editor: props.settings.editor ?? 'vscode',
    notifyErrors: props.settings.notifyErrors ?? true,
    logShell: props.settings.logShell ?? 'gitbash',
    mailUrl: props.settings.mailUrl ?? '',
    notifyMail: props.settings.notifyMail ?? true,
    mailPin: props.settings.mailPin ?? '',
    mailpitMode: props.settings.mailpitMode ?? 'off',
});

// --- Mail ------------------------------------------------------------------
// What's running comes from a live probe when the dialog opens. Downloading
// and removing Mailpit happen right away; everything else saves with Save.

const mail = ref(null); // /mail/status response, null while loading
const mailBusy = ref(''); // '' | 'downloading' | 'removing'
const mailError = ref('');
let stopDownloadWatch = null;

const mailpit = computed(() => mail.value?.mailpit ?? { installed: false, version: null, loginSupported: false });

// A pinned server that isn't running right now still shows, so the choice
// isn't silently lost.
const pinOptions = computed(() => {
    const sources = mail.value?.sources ?? [];
    const options = sources.map((s) => ({ id: s.id, label: `${s.label} · ${hostOf(s.url)}` }));
    if (form.mailPin && !sources.some((s) => s.id === form.mailPin)) {
        const [kind, url] = form.mailPin.split('|');
        options.push({ id: form.mailPin, label: `${kind} · ${hostOf(url)} (not running)` });
    }
    return options;
});

function hostOf(url) {
    return (url ?? '').replace(/^https?:\/\//, '');
}

async function loadMail() {
    const { ok, data } = await postJson('/mail/status');
    mail.value = ok ? data : { sources: [], active: null, mailpit: mailpit.value };
}

async function downloadMailpit() {
    mailError.value = '';
    const { ok, data } = await postJson('/mail/mailpit/download');
    if (!ok || !data?.started) {
        mailError.value = 'Downloading Mailpit only works inside the desktop app.';
        return;
    }

    mailBusy.value = 'downloading';
    stopDownloadWatch?.();
    stopDownloadWatch = onChildProcessExit(data.alias, async (code) => {
        stopDownloadWatch?.();
        stopDownloadWatch = null;
        await loadMail();
        mailBusy.value = '';
        if (!mailpit.value.installed) {
            mailError.value = `The download failed${code ? ` (exit code ${code})` : ''}. Check your connection and try again.`;
        }
    });
}

async function removeMailpit() {
    if (!window.confirm('Remove the downloaded Mailpit? Nexus stops running it, at login too.')) return;
    mailError.value = '';
    mailBusy.value = 'removing';
    const { ok, data } = await deleteJson('/mail/mailpit');
    if (!ok) mailError.value = data?.error ?? 'Couldn\'t remove Mailpit.';
    form.mailpitMode = 'off';
    // The server switched the mode off too; keep the page's props in step.
    router.reload({ only: ['settings'] });
    await loadMail();
    mailBusy.value = '';
}

onMounted(loadMail);
onBeforeUnmount(() => stopDownloadWatch?.());

// Unix always has a real `tail`, so the picker is Windows-only.
const isWindows = computed(() => props.platform === 'Windows');

const SHELL_NOTES = {
    gitbash: 'Uses the GNU tail that ships with Git for Windows. Follows the log by path, so it keeps streaming across rotation.',
    wsl: 'Reads the log through /mnt/…. Reliable, but picks up new lines a little slower than Git Bash.',
    powershell: 'Get-Content -Wait follows the open file handle, so streaming stops silently when the log rotates — with no error. Use Git Bash or WSL if either is installed.',
};

const shellNote = computed(() => SHELL_NOTES[form.logShell] ?? '');

const errors = computed(() => Object.values(form.errors));

function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function save() {
    form.patch('/settings', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
        <div class="flex max-h-[90vh] w-[28rem] max-w-[92vw] flex-col rounded-lg border border-neutral-200 bg-white p-5 shadow-xl dark:border-neutral-700 dark:bg-neutral-900" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <h2 id="settings-title" class="text-base font-semibold">Settings</h2>

            <div class="-mx-5 mt-4 min-h-0 flex-1 space-y-4 overflow-y-auto px-5">
                <div>
                    <label class="block text-xs font-medium text-neutral-500">Theme</label>
                    <select
                        v-model="form.theme"
                        class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-700"
                    >
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-500">PHP binary path (optional)</label>
                    <input
                        v-model="form.phpPath"
                        type="text"
                        placeholder="Leave blank to auto-detect (Herd / PATH)"
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 font-mono text-xs dark:border-neutral-700"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-500">Editor (click-to-source)</label>
                    <select
                        v-model="form.editor"
                        class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-700"
                    >
                        <option value="phpstorm">PhpStorm</option>
                        <option value="vscode">VS Code</option>
                        <option value="vscodium">VSCodium</option>
                        <option value="cursor">Cursor</option>
                        <option value="sublime">Sublime Text</option>
                        <option value="textmate">TextMate</option>
                    </select>
                </div>

                <div v-if="isWindows">
                    <label class="block text-xs font-medium text-neutral-500">Log streaming shell</label>
                    <select
                        v-model="form.logShell"
                        class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-700"
                    >
                        <option value="gitbash">Git Bash — recommended</option>
                        <option value="wsl">WSL</option>
                        <option value="powershell">PowerShell — not recommended</option>
                    </select>
                    <p
                        class="mt-1 text-[11px] leading-snug"
                        :class="form.logShell === 'powershell'
                            ? 'text-amber-600 dark:text-amber-500'
                            : 'text-neutral-500'"
                    >
                        {{ shellNote }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.notifyErrors" type="checkbox" class="rounded border-neutral-300 dark:border-neutral-700" />
                    <span>Desktop notification on log errors</span>
                </label>

                <!-- Mail -->
                <section class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <h3 class="text-sm font-semibold">Mail</h3>

                    <div class="mt-3">
                        <label class="block text-xs font-medium text-neutral-500">Read mail from</label>
                        <select
                            v-model="form.mailPin"
                            class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-700"
                        >
                            <option value="">Automatic — whatever I already run</option>
                            <option v-for="option in pinOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                        <p class="mt-1 text-[11px] leading-snug text-neutral-500">
                            <template v-if="!mail">Looking for mail servers…</template>
                            <template v-else-if="mail.active">Using {{ mail.active.label }} at {{ hostOf(mail.active.url) }}.</template>
                            <template v-else>No mail server is running right now.</template>
                            Automatic picks smtp4dev, then Mailpit, then MailHog.
                        </p>
                    </div>

                    <label class="mt-3 flex items-center gap-2 text-sm">
                        <input v-model="form.notifyMail" type="checkbox" class="rounded border-neutral-300 dark:border-neutral-700" />
                        <span>Notify me about new mail when Nexus isn't in front</span>
                    </label>

                    <div class="mt-3">
                        <label class="block text-xs font-medium text-neutral-500">Mail server URL (optional)</label>
                        <input
                            v-model="form.mailUrl"
                            type="url"
                            placeholder="Only if yours isn't on its usual port"
                            class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 font-mono text-xs dark:border-neutral-700"
                        />
                        <p class="mt-1 text-[11px] text-neutral-400">Nexus looks here first, then at smtp4dev on :5000 and Mailpit or MailHog on :8025.</p>
                    </div>

                    <div class="mt-3 rounded border border-neutral-200 p-3 dark:border-neutral-800">
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-medium">Mailpit for Nexus</div>
                                <div class="text-[11px] text-neutral-500">
                                    <template v-if="mailBusy === 'downloading'">Downloading…</template>
                                    <template v-else-if="mailpit.installed">Downloaded{{ mailpit.version ? ` (${mailpit.version})` : '' }}</template>
                                    <template v-else>A fallback for when you don't run a mail server of your own.</template>
                                </div>
                            </div>
                            <button
                                v-if="!mailpit.installed"
                                type="button"
                                class="shrink-0 rounded bg-emerald-600 px-2.5 py-1 text-xs text-white hover:bg-emerald-500 disabled:opacity-50"
                                :disabled="!!mailBusy || !mail"
                                @click="downloadMailpit"
                            >
                                {{ mailBusy === 'downloading' ? 'Downloading…' : 'Download' }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="shrink-0 rounded border border-neutral-300 px-2.5 py-1 text-xs text-neutral-600 hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                :disabled="!!mailBusy"
                                @click="removeMailpit"
                            >
                                {{ mailBusy === 'removing' ? 'Removing…' : 'Remove' }}
                            </button>
                        </div>

                        <fieldset class="mt-3 space-y-1.5 text-sm" :disabled="!mailpit.installed" :class="mailpit.installed ? '' : 'opacity-50'">
                            <label class="flex items-start gap-2">
                                <input v-model="form.mailpitMode" type="radio" value="off" class="mt-1" />
                                <span>Don't start it</span>
                            </label>
                            <label class="flex items-start gap-2">
                                <input v-model="form.mailpitMode" type="radio" value="nexus" class="mt-1" />
                                <span>
                                    Start it with Nexus
                                    <span class="block text-[11px] text-neutral-500">Only when no other mail server is running. Stops when Nexus quits.</span>
                                </span>
                            </label>
                            <label v-if="mailpit.loginSupported" class="flex items-start gap-2">
                                <input v-model="form.mailpitMode" type="radio" value="login" class="mt-1" />
                                <span>
                                    Start it when I log in
                                    <span class="block text-[11px] text-neutral-500">Keeps running in the background, even with Nexus closed.</span>
                                </span>
                            </label>
                        </fieldset>

                        <p v-if="mailError" class="mt-2 text-[11px] text-red-600 dark:text-red-400">{{ mailError }}</p>
                    </div>
                </section>
            </div>

            <ul v-if="errors.length" class="mt-4 space-y-0.5 text-[11px] text-red-600 dark:text-red-400" role="alert">
                <li v-for="error in errors" :key="error">{{ error }}</li>
            </ul>

            <div class="mt-6 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded bg-neutral-800 px-3 py-1.5 text-sm text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-700 dark:hover:bg-neutral-600"
                    :disabled="form.processing"
                    @click="save"
                >
                    Save
                </button>
            </div>
        </div>
    </div>
</template>
