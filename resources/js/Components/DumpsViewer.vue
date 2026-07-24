<script setup>
// The Dumps tab: a live feed of dump()/dd() payloads from connected projects.
// Collection happens in lib/dumpStream.js (session-wide); this component is
// the view — filter, pause, clear, connect-the-active-project, click-to-source.
import { computed, onMounted, ref, watch } from 'vue';
import { postJson } from '../lib/http.js';
import { clearDumps, dumps, paused, serverError, serverState } from '../lib/dumpStream.js';

const props = defineProps({
    activeProject: { type: Object, default: null },
});

const query = ref('');
const connectStatus = ref(null); // { name, exists, connected, values } | null
const connecting = ref(false);
const connectError = ref('');

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return dumps.value;
    return dumps.value.filter(
        (d) => d.text.toLowerCase().includes(q) || (d.source?.file ?? '').toLowerCase().includes(q),
    );
});

async function loadStatus() {
    connectError.value = '';
    const { ok, data } = await postJson('/dumps/status');
    connectStatus.value = ok ? data?.project ?? null : null;
}

async function connectProject() {
    connecting.value = true;
    connectError.value = '';
    try {
        const { ok, data } = await postJson('/dumps/connect');
        if (ok) await loadStatus();
        else connectError.value = data?.error ?? 'Could not update .env';
    } finally {
        connecting.value = false;
    }
}

function openSource(dump) {
    if (dump.source?.file) {
        postJson('/editor/open', { file: dump.source.file, line: dump.source.line ?? 1 });
    }
}

const time = (ts) => {
    const d = new Date(ts);
    return isNaN(d) ? '' : d.toLocaleTimeString();
};

const shortPath = (file) => (file ?? '').split('/').slice(-2).join('/');

onMounted(loadStatus);
watch(() => props.activeProject?.id, loadStatus);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Status / controls bar -->
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs dark:border-neutral-800">
            <span
                class="inline-flex items-center gap-1.5"
                :class="serverState === 'ready' ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-500'"
            >
                <span
                    class="h-1.5 w-1.5 rounded-full"
                    :class="{
                        'bg-emerald-500': serverState === 'ready',
                        'bg-amber-500': serverState === 'starting',
                        'bg-red-500': serverState === 'error',
                        'bg-neutral-400': serverState === 'idle' || serverState === 'unavailable',
                    }"
                ></span>
                <template v-if="serverState === 'ready'">receiver on 127.0.0.1:9912</template>
                <template v-else-if="serverState === 'starting'">starting receiver…</template>
                <template v-else-if="serverState === 'error'">{{ serverError }}</template>
                <template v-else>receiver needs the desktop runtime</template>
            </span>

            <!-- Active-project wiring -->
            <template v-if="activeProject && connectStatus">
                <span v-if="connectStatus.connected" class="text-neutral-500">
                    · <span class="font-mono">{{ activeProject.name }}</span> connected
                </span>
                <button
                    v-else
                    type="button"
                    class="rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-500 disabled:opacity-50"
                    :disabled="connecting || !connectStatus.exists"
                    :title="connectStatus.exists
                        ? 'Write VAR_DUMPER_FORMAT/SERVER to the project .env — dumps fall back to normal output when Nexus is closed'
                        : 'Project has no .env file'"
                    @click="connectProject"
                >
                    {{ connecting ? 'Connecting…' : `Route ${activeProject.name}'s dump() here` }}
                </button>
            </template>
            <span v-if="connectError" class="text-red-500">{{ connectError }}</span>

            <input
                v-model="query"
                type="search"
                placeholder="Filter dumps…"
                class="ml-auto w-44 rounded border border-neutral-300 bg-white px-2 py-1 text-neutral-800 placeholder:text-neutral-400 focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
            />
            <button
                type="button"
                class="rounded border border-neutral-300 px-2 py-1 dark:border-neutral-700"
                :class="paused ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400' : 'hover:bg-neutral-100 dark:hover:bg-neutral-800'"
                @click="paused = !paused"
            >
                {{ paused ? 'Resume' : 'Pause' }}
            </button>
            <button
                type="button"
                class="rounded border border-neutral-300 px-2 py-1 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                :disabled="!dumps.length"
                @click="clearDumps"
            >
                Clear
            </button>
        </div>

        <!-- Feed -->
        <div class="min-h-0 flex-1 overflow-auto">
            <div v-if="!filtered.length" class="flex h-full flex-col items-center justify-center gap-2 p-6 text-center text-sm text-neutral-400">
                <template v-if="dumps.length">
                    <p>No dumps match the filter.</p>
                </template>
                <template v-else>
                    <p class="font-medium text-neutral-500">No dumps yet.</p>
                    <p class="max-w-md">
                        Connect a project above, then call <code class="rounded bg-neutral-100 px-1 font-mono dark:bg-neutral-800">dump($anything)</code>
                        anywhere in it — every payload lands here live, with a link back to the call site.
                        No package needed; it's plain <code class="font-mono">symfony/var-dumper</code>.
                    </p>
                </template>
            </div>

            <div
                v-for="dump in filtered"
                :key="dump.id"
                class="border-b border-neutral-100 px-3 py-2 dark:border-neutral-800/60"
            >
                <div class="mb-1 flex items-center gap-2 text-[11px] text-neutral-400">
                    <span>{{ time(dump.ts) }}</span>
                    <button
                        v-if="dump.source?.file"
                        type="button"
                        class="truncate font-mono text-sky-600 hover:underline dark:text-sky-400"
                        :title="`Open ${dump.source.file}:${dump.source.line ?? 1} in your editor`"
                        @click="openSource(dump)"
                    >
                        {{ shortPath(dump.source.file) }}<span v-if="dump.source.line">:{{ dump.source.line }}</span>
                    </button>
                </div>
                <pre class="overflow-x-auto whitespace-pre-wrap break-words font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ dump.text }}</pre>
            </div>
        </div>
    </div>
</template>
