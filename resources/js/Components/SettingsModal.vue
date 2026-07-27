<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
    platform: { type: String, default: 'Darwin' },
});

const emit = defineEmits(['close']);

const form = useForm({
    theme: props.settings.theme ?? 'dark',
    phpPath: props.settings.phpPath ?? '',
    editor: props.settings.editor ?? 'phpstorm',
    notifyErrors: props.settings.notifyErrors ?? true,
    logShell: props.settings.logShell ?? 'gitbash',
});

// Unix always has a real `tail`, so the picker is Windows-only.
const isWindows = computed(() => props.platform === 'Windows');

const SHELL_NOTES = {
    gitbash: 'Uses the GNU tail that ships with Git for Windows. Follows the log by path, so it keeps streaming across rotation.',
    wsl: 'Reads the log through /mnt/…. Reliable, but picks up new lines a little slower than Git Bash.',
    powershell: 'Get-Content -Wait follows the open file handle, so streaming stops silently when the log rotates — with no error. Use Git Bash or WSL if either is installed.',
};

const shellNote = computed(() => SHELL_NOTES[form.logShell] ?? '');

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
        <div class="w-96 rounded-lg border border-neutral-200 bg-white p-5 shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-base font-semibold">Settings</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-neutral-500">Theme</label>
                    <select
                        v-model="form.theme"
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 text-sm dark:border-neutral-700"
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
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 text-sm dark:border-neutral-700"
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
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 text-sm dark:border-neutral-700"
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
            </div>

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
