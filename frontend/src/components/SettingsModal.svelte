<script>
  import { createEventDispatcher, onMount } from 'svelte';
  import { Button } from '../lib/components/ui/button';
  import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../lib/components/ui/card';
  import { settings } from '../stores/settings.js';

  export let open = false;
  const dispatch = createEventDispatcher();
  let form = { theme: 'dark', phpPath: '' };

  const themes = [
    { value: 'dark', label: 'Dark' },
    { value: 'light', label: 'Light' }
  ];

  const close = () => dispatch('close');

  onMount(() => {
    const unsubscribe = settings.subscribe(value => {
      form = { ...form, ...value };
    });
    return unsubscribe;
  });

  function handleSubmit(event) {
    event.preventDefault();
    settings.save(form);
    close();
  }
</script>

{#if open}
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
    tabindex="0"
    on:click|self={close}
    on:keydown={(event) => {
      if (event.key === 'Escape') close();
    }}
  >
    <Card class="w-full max-w-xl border-border bg-background shadow-lg">
      <CardHeader class="flex flex-row items-start justify-between gap-4 border-b border-border">
        <div>
          <CardTitle class="text-base">Settings</CardTitle>
          <CardDescription class="text-sm">Personalize your Pulsar experience.</CardDescription>
        </div>
        <Button size="icon" variant="ghost" aria-label="Close" on:click={close}>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 6L6 18"></path>
            <path d="M6 6l12 12"></path>
          </svg>
        </Button>
      </CardHeader>
      <CardContent class="space-y-6">
        <form class="space-y-6" on:submit={handleSubmit}>
          <fieldset class="space-y-2">
            <legend class="block text-sm font-medium">Theme</legend>
            <div class="flex flex-wrap gap-2">
              {#each themes as theme}
                <Button
                  type="button"
                  variant={form.theme === theme.value ? 'secondary' : 'outline'}
                  on:click={() => (form = { ...form, theme: theme.value })}
                  class="px-3"
                >
                  {theme.label}
                </Button>
              {/each}
            </div>
          </fieldset>

          <div class="space-y-2">
            <label for="php-path" class="block text-sm font-medium">PHP binary override</label>
            <p class="text-xs text-muted-foreground">Use this to point Pulsar to a specific php executable (e.g. Herd managed).</p>
            <input
              id="php-path"
              class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="/path/to/php or C:\\path\\to\\php.exe"
              bind:value={form.phpPath}
            />
          </div>

          <div class="flex justify-end gap-2">
            <Button type="button" variant="ghost" on:click={close}>Cancel</Button>
            <Button type="submit">Save</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  </div>
{/if}
