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

    public function test_db_endpoints_require_an_active_project(): void
    {
        $this->post('/workbench/db/tables')->assertStatus(422)->assertJson(['error' => 'No project selected']);
        $this->postJson('/workbench/db/table', ['table' => 'migrations'])->assertStatus(422);
        $this->postJson('/workbench/db/rows', ['table' => 'migrations'])->assertStatus(422);
    }

    public function test_db_table_and_rows_require_a_table_name(): void
    {
        $this->activateSelf();

        $this->postJson('/workbench/db/table', [])->assertStatus(422);
        $this->postJson('/workbench/db/rows', [])->assertStatus(422);
    }

    public function test_db_show_lists_this_apps_tables(): void
    {
        $this->activateSelf();

        $database = $this->post('/workbench/db/tables')->assertOk()->json('database');

        $names = collect($database['tables'] ?? [])
            ->map(fn ($t) => $t['table'] ?? $t['name'] ?? null);
        $this->assertTrue($names->contains('migrations'), 'Expected the migrations table in db:show output');
    }

    public function test_db_rows_returns_a_structured_envelope(): void
    {
        $this->activateSelf();

        $response = $this->postJson('/workbench/db/rows', ['table' => 'migrations'])->assertOk();

        $envelope = $response->json('envelope');
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('table', $envelope);
        $this->assertContains('migration', $envelope['table']['columns'] ?? []);
    }

    public function test_migration_status_lists_migrations(): void
    {
        $this->activateSelf();

        $response = $this->post('/workbench/migrations')->assertOk();

        $this->assertIsArray($response->json('migrations'));
        $this->assertIsInt($response->json('pending'));
    }
}
