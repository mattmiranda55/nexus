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
    <div class="nx-scrim nx-fade fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="emit('close')">
        <div class="nx-plate nx-rise w-[25rem] max-w-full">
            <div class="flex items-center gap-3 border-b border-rule bg-paper px-4 py-2.5">
                <span class="h-2 w-2 shrink-0 rotate-45 bg-accent"></span>
                <h2 class="font-display text-[15px] text-ink">Settings</h2>
                <span class="nx-leader"></span>
            </div>

            <div class="space-y-4 p-4">
                <div>
                    <label class="nx-cap mb-1.5 block">Theme</label>
                    <select v-model="form.theme" class="nx-field w-full">
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                    </select>
                </div>

                <div>
                    <label class="nx-cap mb-1.5 block">PHP binary path <span class="text-ink-3">· optional</span></label>
                    <input
                        v-model="form.phpPath"
                        type="text"
                        placeholder="Auto-detect (Herd / PATH)"
                        class="nx-field nx-field-mono w-full"
                    />
                </div>

                <div>
                    <label class="nx-cap mb-1.5 block">Editor · click-to-source</label>
                    <select v-model="form.editor" class="nx-field w-full">
                        <option value="phpstorm">PhpStorm</option>
                        <option value="vscode">VS Code</option>
                        <option value="vscodium">VSCodium</option>
                        <option value="cursor">Cursor</option>
                        <option value="sublime">Sublime Text</option>
                        <option value="textmate">TextMate</option>
                    </select>
                </div>

                <div v-if="isWindows">
                    <label class="nx-cap mb-1.5 block">Log streaming shell</label>
                    <select v-model="form.logShell" class="nx-field w-full">
                        <option value="gitbash">Git Bash — recommended</option>
                        <option value="wsl">WSL</option>
                        <option value="powershell">PowerShell — not recommended</option>
                    </select>
                    <p
                        class="mt-2 border-l-2 pl-2.5 text-[11px] leading-snug"
                        :class="form.logShell === 'powershell'
                            ? 'border-warn text-warn'
                            : 'border-rule-2 text-ink-3'"
                    >
                        {{ shellNote }}
                    </p>
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 border-t border-rule pt-4 text-[12.5px] text-ink-2">
                    <input v-model="form.notifyErrors" type="checkbox" class="nx-check" />
                    <span>Desktop notification on log errors</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 border-t border-rule bg-paper px-4 py-3">
                <button type="button" class="nx-btn" @click="emit('close')">
                    Cancel
                </button>
                <button
                    type="button"
                    class="nx-btn nx-btn-accent px-4"
                    :disabled="form.processing"
                    @click="save"
                >
                    {{ form.processing ? 'Saving' : 'Save' }}
                </button>
            </div>
        </div>
    </div>
</template>
