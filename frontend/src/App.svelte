<script>
  import '@fontsource-variable/reddit-mono';
  import '@fontsource-variable/nunito';
  import Sidebar from './components/Sidebar.svelte';
  import Editor from './components/Editor.svelte';
  import Output from './components/Output.svelte';
  import Toolbar from './components/Toolbar.svelte';
  import { cn } from './lib/utils';
  import { layout } from './stores/app.js';
</script>

<main class="flex h-screen w-screen bg-background text-foreground">
  <Sidebar />
  <div class="flex min-w-0 flex-1 flex-col">
    <Toolbar />
    <div
      class={cn(
        'flex min-h-0 flex-1 bg-muted/10',
        $layout === 'vertical' ? 'flex-col divide-y divide-border' : 'divide-x divide-border'
      )}
    >
      <Editor />
      <Output />
    </div>
  </div>
</main>
