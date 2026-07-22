<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private function activateSelf(): void
    {
        // This app is itself a Laravel project, so point the workbench at it.
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);
    }

    public function test_endpoints_require_an_active_project(): void
    {
        $this->post('/workbench/routes')->assertStatus(422)->assertJson(['error' => 'No project selected']);
        $this->post('/workbench/models')->assertStatus(422);
    }

    public function test_models_are_discovered_from_app_models(): void
    {
        $this->activateSelf();

        $models = $this->post('/workbench/models')->assertOk()->json('models');

        $this->assertContains('Project', $models);
        $this->assertContains('Setting', $models);
    }

    public function test_routes_list_returns_the_registered_routes(): void
    {
        $this->activateSelf();

        $routes = $this->post('/workbench/routes')->assertOk()->json('routes');

        $this->assertNotEmpty($routes);
        $uris = collect($routes)->pluck('uri');
        $this->assertTrue($uris->contains('tinker'), 'Expected the tinker route in route:list output');
    }

    public function test_migration_status_lists_migrations(): void
    {
        $this->activateSelf();

        $response = $this->post('/workbench/migrations')->assertOk();

        $this->assertIsArray($response->json('migrations'));
        $this->assertIsInt($response->json('pending'));
    }
}
