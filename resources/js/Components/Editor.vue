<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EditorView, basicSetup } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { php } from '@codemirror/lang-php';
import { oneDark } from '@codemirror/theme-one-dark';

const props = defineProps({
    modelValue: { type: String, default: '' },
    dark: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue', 'run']);

const host = ref(null);
let view = null;
const themeCompartment = new Compartment();

function themeExtension(dark) {
    // oneDark for dark mode; CodeMirror's default (light) otherwise.
    return dark ? oneDark : [];
}

onMounted(() => {
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                // Cmd/Ctrl+Enter runs the snippet (highest precedence).
                keymap.of([{
                    key: 'Mod-Enter',
                    preventDefault: true,
                    run: () => {
                        emit('run');
                        return true;
                    },
                }]),
                basicSetup,
                php({ plain: true }),
                themeCompartment.of(themeExtension(props.dark)),
                EditorView.updateListener.of((v) => {
                    if (v.docChanged) {
                        emit('update:modelValue', v.state.doc.toString());
                    }
                }),
            ],
        }),
    });
});

onBeforeUnmount(() => view?.destroy());

// Sync external programmatic changes without disturbing local typing.
watch(
    () => props.modelValue,
    (val) => {
        if (view && val !== view.state.doc.toString()) {
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: val },
            });
        }
    },
);

// Swap the editor theme when the app theme changes.
watch(
    () => props.dark,
    (dark) => {
        view?.dispatch({
            effects: themeCompartment.reconfigure(themeExtension(dark)),
        });
    },
);
</script>

<template>
    <div ref="host" class="h-full w-full overflow-auto text-sm"></div>
</template>
