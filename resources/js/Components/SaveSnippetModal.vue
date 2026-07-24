<script setup>
// Names the current editor buffer and saves it to the snippet library.
// A modal (not window.prompt) because Electron doesn't implement prompt().
import { onMounted, ref } from 'vue';

defineProps({
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'save']);

const name = ref('');
const global = ref(false);
const input = ref(null);

onMounted(() => input.value?.focus());

function submit() {
    const trimmed = name.value.trim();
    if (trimmed) emit('save', { name: trimmed, global: global.value });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
        <div class="w-96 rounded-lg border border-neutral-200 bg-white p-5 shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-base font-semibold">Save snippet</h2>
            <p class="mt-1 text-xs text-neutral-500">Saves the current editor buffer. Same name overwrites.</p>

            <input
                ref="input"
                v-model="name"
                type="text"
                placeholder="Snippet name…"
                class="mt-4 w-full rounded border border-neutral-300 bg-transparent px-2 py-1.5 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700"
                @keydown.enter.prevent="submit"
                @keydown.esc="emit('close')"
            />

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input v-model="global" type="checkbox" class="rounded border-neutral-300 dark:border-neutral-700" />
                <span>Available in all projects</span>
            </label>

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
                    class="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-500 disabled:opacity-50"
                    :disabled="saving || !name.trim()"
                    @click="submit"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </div>
    </div>
</template>
