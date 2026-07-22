<script setup>
// C2 — route:list rendered as a filterable/sortable grid.
import { computed, ref, watch } from 'vue';
import { postJson } from '../../lib/http.js';
import { toTable } from '../../lib/table.js';
import OutputTable from '../OutputTable.vue';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const routes = ref([]);
const status = ref('idle'); // idle | loading | ready | error
const error = ref('');

const COLUMNS = ['method', 'uri', 'name', 'action', 'middleware'];
const table = computed(() => (routes.value.length ? toTable(routes.value, COLUMNS) : null));

async function load() {
    status.value = 'loading';
    error.value = '';
    const { ok, data } = await postJson('/workbench/routes');
    if (!ok) {
        status.value = 'error';
        error.value = data?.error ?? 'Failed to load routes';
        return;
    }
    routes.value = data?.routes ?? [];
    status.value = 'ready';
}

watch(() => props.activeProject?.id, load, { immediate: true });
</script>

<template>
    <div class="h-full min-h-0">
        <div v-if="status === 'loading'" class="p-4 text-sm text-neutral-400">Loading routes…</div>
        <div v-else-if="status === 'error'" class="p-4 text-sm text-red-500">{{ error }}</div>
        <OutputTable v-else-if="table" :table="table" />
        <div v-else class="p-4 text-sm text-neutral-400">No routes found.</div>
    </div>
</template>
