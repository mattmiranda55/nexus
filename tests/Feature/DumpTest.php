<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\ChildProcess;
use Tests\TestCase;

class DumpTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/nexus-dumps-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function activateProject(): Project
    {
        $project = Project::create(['name' => 'demo', 'path' => $this->dir]);
        Setting::current()->update(['active_project_id' => $project->id]);

        return $project;
    }

    public function test_start_launches_the_dump_server_as_a_persistent_child_process(): void
    {
        ChildProcess::fake();

        $this->post('/dumps/start')->assertOk()->assertJson(['status' => 'started']);

        ChildProcess::assertArtisan(
            fn ($cmd, $alias, $env = null, $persistent = null, $iniSettings = null) => $alias === 'dumps'
                && $cmd === ['nexus:dump-server', '--host=127.0.0.1:9912']
                && $persistent === true,
        );
    }

    public function test_stop_stops_the_listener(): void
    {
        ChildProcess::fake();

        $this->post('/dumps/stop')->assertOk()->assertJson(['status' => 'stopped']);

        ChildProcess::assertStop('dumps');
    }

    public function test_status_without_a_project_reports_only_the_host(): void
    {
        $this->post('/dumps/status')
            ->assertOk()
            ->assertJson(['host' => '127.0.0.1:9912', 'project' => null]);
    }

    public function test_status_reflects_the_active_projects_env(): void
    {
        $this->activateProject();
        file_put_contents($this->dir.'/.env', "APP_NAME=Demo\n");

        $this->post('/dumps/status')
            ->assertOk()
            ->assertJsonPath('project.connected', false)
            ->assertJsonPath('project.exists', true);
    }

    public function test_connect_writes_the_var_dumper_keys(): void
    {
        $this->activateProject();
        file_put_contents($this->dir.'/.env', "APP_NAME=Demo\n");

        $this->post('/dumps/connect')->assertOk()->assertJson(['ok' => true]);

        $env = file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString('VAR_DUMPER_FORMAT=server', $env);
        $this->assertStringContainsString('VAR_DUMPER_SERVER=127.0.0.1:9912', $env);
        $this->assertStringContainsString('APP_NAME=Demo', $env); // untouched

        $this->post('/dumps/status')->assertJsonPath('project.connected', true);
    }

    public function test_connect_requires_an_active_project(): void
    {
        $this->post('/dumps/connect')->assertStatus(422)->assertJson(['error' => 'No project selected']);
    }

    public function test_connect_fails_cleanly_without_an_env_file(): void
    {
        $this->activateProject();

        $this->post('/dumps/connect')->assertStatus(422);
    }
}
