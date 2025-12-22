<script>
  import { Badge } from '../lib/components/ui/badge';
  import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle
  } from '../lib/components/ui/card';
  import { output, isRunning } from '../stores/app.js';
</script>

<Card class="flex min-h-0 flex-1 flex-col rounded-none border-0 bg-card">
  <CardHeader class="flex flex-row items-center justify-between border-b border-border bg-background/40 py-4">
    <div>
      <CardTitle class="text-sm">Output</CardTitle>
      <CardDescription class="text-xs">Laravel Tinker results</CardDescription>
    </div>
    {#if $isRunning}
      <Badge variant="secondary" class="border-primary/30 bg-primary/10 text-xs text-primary">
        Running...
      </Badge>
    {/if}
  </CardHeader>
  <CardContent class="flex-1 overflow-auto bg-card px-4 py-4 font-mono text-sm leading-6 text-foreground/90">
    <pre class="m-0 whitespace-pre-wrap break-words">{$output || 'Run your code to see output here...'}</pre>
  </CardContent>
</Card>
