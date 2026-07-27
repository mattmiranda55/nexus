<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_page_renders_with_defaults(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console')
                ->where('settings.theme', 'dark')
                ->where('activeProjectId', null)
                ->has('projects', 0));
    }

    public function test_activating_a_project_persists_it_as_active(): void
    {
        $project = Project::create(['name' => 'demo', 'path' => '/tmp/demo']);

        $this->post("/projects/{$project->id}/activate")
            ->assertRedirect();

        $this->assertSame($project->id, (int) Setting::current()->active_project_id);
    }

    public function test_settings_update_persists(): void
    {
        $this->patch('/settings', [
            'theme' => 'light',
            'phpPath' => '/usr/bin/php',
            'editor' => 'phpstorm',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = Setting::current();
        $this->assertSame('light', $settings->theme);
        $this->assertSame('/usr/bin/php', $settings->php_path);
    }

    public function test_settings_update_validates_theme(): void
    {
        $this->patch('/settings', ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');
    }

    public function test_log_shell_persists_and_defaults_when_omitted(): void
    {
        $base = ['theme' => 'dark', 'editor' => 'phpstorm'];

        $this->patch('/settings', $base + ['logShell' => 'wsl'])->assertRedirect();
        $this->assertSame('wsl', Setting::current()->log_shell);

        // The picker is hidden off Windows, so the field simply won't be sent.
        $this->patch('/settings', $base)->assertRedirect();
        $this->assertSame('gitbash', Setting::current()->log_shell);
    }

    public function test_settings_update_rejects_an_unknown_log_shell(): void
    {
        $this->patch('/settings', [
            'theme' => 'dark',
            'editor' => 'phpstorm',
            'logShell' => 'cmd',
        ])->assertSessionHasErrors('logShell');
    }

    public function test_console_page_exposes_the_platform(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('platform', PHP_OS_FAMILY)
                ->where('settings.logShell', 'gitbash'));
    }

    public function test_removing_active_project_clears_the_active_setting(): void
    {
        $project = Project::create(['name' => 'demo', 'path' => '/tmp/demo']);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->delete("/projects/{$project->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertNull(Setting::current()->active_project_id);
    }
}
