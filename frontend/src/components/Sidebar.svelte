<script>
  import { onMount } from 'svelte';
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

  async function removeProject(e, project) {
    e.stopPropagation();
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

<aside class="sidebar">
  <button class="add-project" on:click={addProject} title="Add Laravel Project">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 5v14M5 12h14"/>
    </svg>
  </button>
  
  <div class="projects">
    {#each $projects as project}
      <button 
        class="project-btn" 
        class:active={$currentProject?.id === project.id}
        on:click={() => currentProject.set(project)}
        on:contextmenu|preventDefault={(e) => removeProject(e, project)}
        title={`${project.name}\n${project.path}`}
      >
        {project.name.charAt(0).toUpperCase()}
      </button>
    {/each}
  </div>

  {#if $projects.length === 0}
    <div class="no-projects">
      <span>Add a Laravel project to get started</span>
    </div>
  {/if}
</aside>

<style>
  .sidebar {
    width: 48px;
    background: #1e1e1e;
    border-right: 1px solid #333;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 0;
    gap: 8px;
  }

  .add-project {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 2px dashed #555;
    background: transparent;
    color: #888;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
  }

  .add-project:hover {
    border-color: #f55247;
    color: #f55247;
  }

  .projects {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
  }

  .project-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    background: #333;
    color: #ccc;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
  }

  .project-btn:hover {
    background: #444;
  }

  .project-btn.active {
    background: #f55247;
    color: white;
  }

  .no-projects {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    color: #555;
    font-size: 11px;
    margin-top: 16px;
    text-align: center;
  }
</style>
