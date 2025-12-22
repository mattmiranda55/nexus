<script>
  import { onMount, onDestroy } from 'svelte';
  import loader from '@monaco-editor/loader';
  import { code } from '../stores/app.js';

  let editorContainer;
  let editor;

  onMount(async () => {
    const monaco = await loader.init();
    
    editor = monaco.editor.create(editorContainer, {
      value: $code,
      language: 'php',
      theme: 'vs-dark',
      fontSize: 14,
      fontFamily: '"Reddit Mono Variable", monospace',
      minimap: { enabled: false },
      automaticLayout: true,
      padding: { top: 16 },
      scrollBeyondLastLine: false,
      lineNumbers: 'on',
      renderLineHighlight: 'line',
      tabSize: 4,
    });

    editor.onDidChangeModelContent(() => {
      code.set(editor.getValue());
    });
  });

  onDestroy(() => {
    if (editor) {
      editor.dispose();
    }
  });
</script>

<div class="flex-1 min-h-0 bg-card" bind:this={editorContainer}></div>
