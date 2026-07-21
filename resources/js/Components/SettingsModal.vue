<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    theme: props.settings.theme ?? 'dark',
    phpPath: props.settings.phpPath ?? '',
});

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
