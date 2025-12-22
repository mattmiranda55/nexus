<script>
  import { code, output, isRunning, layout, currentProject } from '../stores/app.js';
  import { RunTinker } from '../../wailsjs/go/main/App.js';
  import { Button } from '../lib/components/ui/button';

  async function runCode() {
    console.log('[Toolbar] runCode called');
    if (!$currentProject) {
      console.log('[Toolbar] No current project selected');
      output.set('Error: Please add a Laravel project first');
      return;
    }

    console.log('[Toolbar] Running code for project:', $currentProject);
    console.log('[Toolbar] Code to execute:', $code);
    isRunning.set(true);
    output.set('');
    
    try {
      console.log('[Toolbar] Calling RunTinker...');
      const result = await RunTinker($currentProject.path, $code);
      console.log('[Toolbar] RunTinker returned:', result);
      output.set(result);
    } catch (err) {
      console.error('[Toolbar] Error running tinker:', err);
      output.set(`Error: ${err}`);
    } finally {
      isRunning.set(false);
      console.log('[Toolbar] Execution complete');
    }
  }

  function toggleLayout() {
    layout.update(l => l === 'horizontal' ? 'vertical' : 'horizontal');
  }

  function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
      e.preventDefault();
      runCode();
    }
  }
</script>

<svelte:window on:keydown={handleKeydown} />

<div class="flex items-center justify-between border-b border-border bg-background/70 px-4 py-2">
  <div class="flex items-center gap-3">
    <Button class="gap-2" on:click={runCode} disabled={$isRunning || !$currentProject}>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
        <path d="M8 5v14l11-7z" />
      </svg>
      {$isRunning ? 'Running...' : 'Run'}
    </Button>
    <span class="hidden text-xs text-muted-foreground sm:inline">⌘ + Enter</span>
  </div>

  <div class="flex items-center gap-3">
    {#if $currentProject}
      <span class="truncate text-sm font-medium text-foreground/90 max-w-[180px] sm:max-w-xs">
        {$currentProject.name}
      </span>
    {:else}
      <span class="text-sm text-muted-foreground">No project selected</span>
    {/if}
    <Button variant="outline" size="icon" on:click={toggleLayout} title="Toggle Layout">
      {#if $layout === 'horizontal'}
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2" />
          <line x1="12" y1="3" x2="12" y2="21" />
        </svg>
      {:else}
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2" />
          <line x1="3" y1="12" x2="21" y2="12" />
        </svg>
      {/if}
    </Button>
  </div>
</div>
