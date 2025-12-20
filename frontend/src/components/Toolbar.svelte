<script>
  import { code, output, isRunning, layout, currentProject } from '../stores/app.js';
  import { RunTinker } from '../../wailsjs/go/main/App.js';

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

<div class="toolbar">
  <div class="left">
    <button class="run-btn" on:click={runCode} disabled={$isRunning || !$currentProject}>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M8 5v14l11-7z"/>
      </svg>
      {$isRunning ? 'Running...' : 'Run'}
    </button>
    <span class="shortcut">⌘ + Enter</span>
  </div>

  <div class="center">
    {#if $currentProject}
      <span class="project-name">{$currentProject.name}</span>
    {:else}
      <span class="no-project">No project selected</span>
    {/if}
  </div>
  
  <button class="layout-btn" on:click={toggleLayout} title="Toggle Layout">
    {#if $layout === 'horizontal'}
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <line x1="12" y1="3" x2="12" y2="21"/>
      </svg>
    {:else}
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
      </svg>
    {/if}
  </button>
</div>

<style>
  .toolbar {
    height: 40px;
    background: #252526;
    border-bottom: 1px solid #333;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 12px;
  }

  .left {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .center {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
  }

  .project-name {
    font-size: 12px;
    color: #888;
  }

  .no-project {
    font-size: 12px;
    color: #555;
    font-style: italic;
  }

  .shortcut {
    font-size: 11px;
    color: #555;
  }

  .run-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f55247;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s;
  }

  .run-btn:hover:not(:disabled) {
    background: #ff6b5b;
  }

  .run-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .layout-btn {
    padding: 6px;
    background: transparent;
    color: #888;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .layout-btn:hover {
    background: #333;
    color: #ccc;
  }
</style>
