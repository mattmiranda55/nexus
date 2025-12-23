<script>
  import { onMount } from 'svelte';
  import { Button } from '../lib/components/ui/button';
  import { Badge } from '../lib/components/ui/badge';
  import { cn } from '../lib/utils';
  import { projects, currentProject } from '../stores/app.js';
  import { GetProjects, AddProject, RemoveProject, SelectDirectory } from '../../wailsjs/go/main/App.js';

  onMount(async () => {
    console.log('[Sidebar] Component mounted, loading projects...');
    try {
      const savedProjects = await GetProjects();
      console.log('[Sidebar] GetProjects returned:', savedProjects);
      projects.set(savedProjects || []);
      if (savedProjects && savedProjects.length > 0) {
        console.log('[Sidebar] Setting current project to:', savedProjects[0]);
        currentProject.set(savedProjects[0]);
      }
    } catch (err) {
      console.error('[Sidebar] Error loading projects:', err);
    }
  });

  async function addProject() {
    console.log('[Sidebar] addProject called');
    try {
      console.log('[Sidebar] Opening directory picker...');
      const path = await SelectDirectory();
      console.log('[Sidebar] SelectDirectory returned:', path);
      
      if (!path) {
        console.log('[Sidebar] No path selected, aborting');
        return;
      }
      
      // Auto-use folder name as project name
      const name = path.split('/').pop();
      console.log('[Sidebar] Using folder name as project name:', name);

      console.log('[Sidebar] Calling AddProject with name:', name, 'path:', path);
      const project = await AddProject(name, path);
      console.log('[Sidebar] AddProject returned:', project);
      projects.update(p => [...p, project]);
      currentProject.set(project);
      console.log('[Sidebar] Project added successfully');
    } catch (err) {
      console.error('[Sidebar] Error in addProject:', err);
      alert(err);
    }
  }

  async function removeProject(project) {
    console.log('[Sidebar] removeProject called for:', project);
    if (confirm(`Remove "${project.name}" from projects?`)) {
      try {
        console.log('[Sidebar] Calling RemoveProject with id:', project.id);
        await RemoveProject(project.id);
        console.log('[Sidebar] RemoveProject completed');
        projects.update(p => p.filter(pr => pr.id !== project.id));
        if ($currentProject?.id === project.id) {
          currentProject.set($projects[0] || null);
        }
      } catch (err) {
        console.error('[Sidebar] Error removing project:', err);
      }
    }
  }
</script>

<aside class="flex h-full w-64 flex-col border-r border-border bg-card/40 p-4">
  <div class="flex items-center justify-between gap-2">
    <div>
      <p class="text-sm font-semibold">Projects</p>
      <p class="text-xs text-muted-foreground">Right click to remove</p>
    </div>
    <Button size="icon" variant="outline" on:click={addProject} title="Add Laravel Project">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 5v14"></path>
        <path d="M5 12h14"></path>
      </svg>
    </Button>
  </div>

  <div class="mt-4 space-y-2 overflow-y-auto pr-1">
    {#each $projects as project}
      <Button
        variant={$currentProject?.id === project.id ? 'secondary' : 'ghost'}
        class="group flex w-full items-center justify-between gap-3 rounded-lg border border-transparent px-3 py-2 text-left"
        on:click={() => currentProject.set(project)}
        on:contextmenu={(e) => {
          e.preventDefault();
          removeProject(project);
        }}
        title={`${project.name}\n${project.path}`}
      >
        <div class="flex min-w-0 items-center gap-3">
          <div
            class={cn(
              'flex h-9 w-9 items-center justify-center rounded-md bg-muted text-sm font-semibold text-foreground transition-colors',
              $currentProject?.id === project.id && 'bg-primary text-primary-foreground'
            )}
          >
            {project.name.charAt(0).toUpperCase()}
          </div>
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold leading-tight">{project.name}</p>
            <p class="truncate text-xs text-muted-foreground">{project.path}</p>
          </div>
        </div>
        {#if $currentProject?.id === project.id}
          <Badge
            variant="secondary"
            class="hidden text-[10px] uppercase tracking-wide text-muted-foreground group-hover:inline-flex"
          >
            Active
          </Badge>
        {/if}
      </Button>
    {/each}
  </div>

  {#if $projects.length === 0}
    <div class="mt-6 flex flex-1 flex-col items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/10 px-4 py-6 text-center">
      <p class="text-sm font-medium text-muted-foreground">Add a Laravel project to get started</p>
    </div>
  {/if}
</aside>
