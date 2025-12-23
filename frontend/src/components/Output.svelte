<script>
  import { onDestroy } from 'svelte';
  import { tick } from 'svelte';
  import { EventsOn } from '../../wailsjs/runtime/runtime.js';
  import { StartLogTail, StopLogTail } from '../../wailsjs/go/main/App.js';
  import { currentProject, isRunning, logStatus, logs, output, outputTab } from '../stores/app.js';

  let logContentEl;
  let currentLogProjectId = null;
  let startInProgress = false;
  let logUpdateUnsub;
  let logErrorUnsub;
  let lastProjectId = null;
  let parsedLogEntries = [];

  function selectTab(tab) {
    outputTab.set(tab);
    if (tab === 'logs' && !$currentProject) {
      logs.set('Add a Laravel project to view logs.');
      logStatus.set('idle');
    }
  }

  $: if ($outputTab !== 'logs' && currentLogProjectId) {
    teardownLogTail();
  }

  $: if ($outputTab === 'logs' && $currentProject) {
    startLogTail();
  }

  $: if ($currentProject?.id !== lastProjectId) {
    lastProjectId = $currentProject?.id || null;
    outputTab.set('tinker');
    logs.set('');
    logStatus.set('idle');
    teardownLogTail();
  }

  $: parsedLogEntries = buildParsedLogs($logs);

  onDestroy(() => {
    teardownLogTail();
  });

  async function startLogTail() {
    if (startInProgress) return;

    if (!$currentProject) {
      logs.set('Add a Laravel project to view logs.');
      logStatus.set('idle');
      return;
    }

    if (currentLogProjectId === $currentProject.id && ($logStatus === 'tailing' || $logStatus === 'loading')) {
      return;
    }

    startInProgress = true;
    teardownLogTail();

    currentLogProjectId = $currentProject.id;
    logStatus.set('loading');
    logs.set('');

    logUpdateUnsub = EventsOn('log:update', (line) => {
      logs.update((content) => (content ? `${content}\n${line}` : line));
      scrollLogs();
    });

    logErrorUnsub = EventsOn('log:error', (message) => {
      logStatus.set('error');
      logs.update((content) => (content ? `${content}\n[error] ${message}` : `[error] ${message}`));
    });

    try {
      const initial = await StartLogTail($currentProject.path);
      logs.set(initial || 'Waiting for new log entries...');
      logStatus.set('tailing');
      await scrollLogs();
    } catch (error) {
      logStatus.set('error');
      logs.set(`Error starting log tail: ${error?.message || error}`);
      currentLogProjectId = null;
    } finally {
      startInProgress = false;
    }
  }

  async function teardownLogTail() {
    try {
      await StopLogTail();
    } catch (error) {
      console.error('[Output] Error stopping log tail:', error);
    }

    if (logUpdateUnsub) {
      logUpdateUnsub();
      logUpdateUnsub = null;
    }
    if (logErrorUnsub) {
      logErrorUnsub();
      logErrorUnsub = null;
    }

    currentLogProjectId = null;
    logStatus.set('idle');
  }

  async function scrollLogs() {
    await tick();
    if ($outputTab === 'logs' && logContentEl) {
      logContentEl.scrollTop = logContentEl.scrollHeight;
    }
  }

  function buildParsedLogs(content) {
    if (!content) return [];

    const lines = content.split(/\r?\n/);
    const entries = [];
    let current = null;

    for (const rawLine of lines) {
      if (!rawLine) continue;

      const parsed = parseLogLine(rawLine);

      if (parsed.isNew) {
        if (current) {
          entries.push(current);
        }

        current = {
          timestamp: parsed.timestamp,
          level: normalizeLevel(parsed.level),
          originalLevel: parsed.level,
          message: parsed.message,
          details: [],
          raw: rawLine,
        };
      } else if (current) {
        current.details.push(rawLine);
      } else {
        current = {
          timestamp: '',
          level: 'info',
          originalLevel: 'INFO',
          message: rawLine,
          details: [],
          raw: rawLine,
        };
      }
    }

    if (current) {
      entries.push(current);
    }

    return entries;
  }

  function parseLogLine(line) {
    const trimmed = line.trim();

    const laravelMatch = trimmed.match(/^\[([^\]]+)\]\s+(?:[\w-]+\.)?([A-Z]+):\s*(.*)$/);
    if (laravelMatch) {
      return {
        isNew: true,
        timestamp: laravelMatch[1],
        level: laravelMatch[2],
        message: laravelMatch[3] || '',
      };
    }

    const fallbackMatch = trimmed.match(/^\[([^\]]+)\]\s+([^:]+):\s*(.*)$/);
    if (fallbackMatch) {
      return {
        isNew: true,
        timestamp: fallbackMatch[1],
        level: fallbackMatch[2],
        message: fallbackMatch[3] || '',
      };
    }

    return { isNew: false, message: trimmed };
  }

  function normalizeLevel(level = '') {
    const normalized = level.toLowerCase();

    if (['emergency', 'alert', 'critical', 'error', 'err', 'exception'].includes(normalized)) return 'error';
    if (['warning', 'warn'].includes(normalized)) return 'warning';
    if (['notice', 'info', 'information'].includes(normalized)) return 'info';
    if (['debug', 'trace'].includes(normalized)) return 'debug';
    if (['success', 'ok'].includes(normalized)) return 'success';

    return 'info';
  }

  function levelLabel(entry) {
    if (entry.originalLevel) return entry.originalLevel.toUpperCase();
    return entry.level.toUpperCase();
  }
</script>

<div class="output-container">
  <div class="output-header">
    <div class="tabs">
      <button class:active={$outputTab === 'tinker'} on:click={() => selectTab('tinker')}>
        Tinker
      </button>
      <button class:active={$outputTab === 'logs'} on:click={() => selectTab('logs')} disabled={!$currentProject}>
        Logs
      </button>
    </div>

    <div class="status">
      {#if $outputTab === 'tinker'}
        {#if $isRunning}
          <span class="running">Running...</span>
        {/if}
      {:else if $logStatus === 'loading'}
        <span class="running">Starting log tail...</span>
      {:else if $logStatus === 'tailing'}
        <span class="running">Tailing logs...</span>
      {:else if $logStatus === 'error'}
        <span class="error">Log tail error</span>
      {/if}
    </div>
  </div>

  {#if $outputTab === 'tinker'}
    <pre class="output-content">{$output || 'Run your code to see output here...'}</pre>
  {:else}
    <div class="output-content logs" bind:this={logContentEl}>
      {#if parsedLogEntries.length === 0}
        <div class="log-empty">Waiting for new log entries...</div>
      {:else}
        {#each parsedLogEntries as entry, index}
          <div class={`log-entry level-${entry.level}`}>
            <div class="log-entry__top">
              <span class="log-entry__timestamp">{entry.timestamp || '—'}</span>
              <span class="log-entry__chip">{levelLabel(entry)}</span>
              <span class="log-entry__message">{entry.message || entry.raw}</span>
            </div>

            {#if entry.details.length}
              <pre class="log-entry__details">{entry.details.join('\n')}</pre>
            {/if}
          </div>
        {/each}
      {/if}
    </div>
  {/if}
</div>

<style>
  .output-container {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    background: #1e1e1e;
    border-left: 1px solid #333;
  }

  .output-header {
    padding: 8px 12px;
    background: #252526;
    border-bottom: 1px solid #333;
    font-size: 12px;
    color: #888;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }

  .tabs {
    display: flex;
    gap: 8px;
  }

  .tabs button {
    padding: 6px 10px;
    background: #1e1e1e;
    color: #888;
    border: 1px solid #333;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
  }

  .tabs button:hover:not(:disabled) {
    background: #333;
    color: #ccc;
  }

  .tabs button.active {
    background: #f55247;
    color: white;
    border-color: #f55247;
  }

  .tabs button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .status {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .running {
    color: #0078d4;
  }

  .error {
    color: #f55247;
  }

  .output-content {
    flex: 1;
    margin: 0;
    padding: 16px;
    overflow: auto;
    font-family: "Reddit Mono Variable", monospace;
    font-size: 13px;
    color: #d4d4d4;
    white-space: pre-wrap;
    word-wrap: break-word;
  }

  .output-content.logs {
    background: #0d1117;
    display: flex;
    flex-direction: column;
    gap: 10px;
    white-space: normal;
  }

  .log-empty {
    color: #778899;
    font-style: italic;
  }

  .log-entry {
    background: #111827;
    border: 1px solid #1f2937;
    border-radius: 10px;
    padding: 12px 14px 10px 16px;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.25);
    position: relative;
  }

  .log-entry::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    border-radius: 10px 0 0 10px;
    background: #334155;
  }

  .log-entry__top {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .log-entry__timestamp {
    color: #94a3b8;
    font-size: 12px;
    letter-spacing: 0.02em;
  }

  .log-entry__chip {
    padding: 4px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid #334155;
    background: rgba(148, 163, 184, 0.1);
  }

  .log-entry__message {
    color: #e5e7eb;
  }

  .log-entry__details {
    margin: 10px 0 0;
    padding: 10px 12px;
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid #1f2937;
    border-radius: 8px;
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.55;
    white-space: pre-wrap;
  }

  .log-entry.level-info::before {
    background: #3b82f6;
  }

  .log-entry.level-info .log-entry__chip {
    background: rgba(59, 130, 246, 0.12);
    border-color: rgba(59, 130, 246, 0.3);
    color: #bfdbfe;
  }

  .log-entry.level-warning::before {
    background: #f59e0b;
  }

  .log-entry.level-warning .log-entry__chip {
    background: rgba(245, 158, 11, 0.12);
    border-color: rgba(245, 158, 11, 0.3);
    color: #fde68a;
  }

  .log-entry.level-error::before {
    background: #ef4444;
  }

  .log-entry.level-error .log-entry__chip {
    background: rgba(239, 68, 68, 0.12);
    border-color: rgba(239, 68, 68, 0.35);
    color: #fecdd3;
  }

  .log-entry.level-debug::before {
    background: #8b5cf6;
  }

  .log-entry.level-debug .log-entry__chip {
    background: rgba(139, 92, 246, 0.12);
    border-color: rgba(139, 92, 246, 0.3);
    color: #ddd6fe;
  }

  .log-entry.level-success::before {
    background: #10b981;
  }

  .log-entry.level-success .log-entry__chip {
    background: rgba(16, 185, 129, 0.12);
    border-color: rgba(16, 185, 129, 0.32);
    color: #a7f3d0;
  }

  .log-entry.level-error .log-entry__message,
  .log-entry.level-error .log-entry__details {
    color: #fecdd3;
  }

  .log-entry.level-warning .log-entry__message,
  .log-entry.level-warning .log-entry__details {
    color: #fef3c7;
  }

  .log-entry.level-debug .log-entry__message,
  .log-entry.level-debug .log-entry__details {
    color: #ede9fe;
  }
</style>
