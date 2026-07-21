<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\ChildProcess;
use Tests\TestCase;

class LogTailTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_requires_an_active_project(): void
    {
        ChildProcess::fake();

        $this->post('/logs/start')
            ->assertStatus(422)
            ->assertJson(['error' => 'No project selected']);
    }

    public function test_start_tails_the_active_project_log(): void
    {
        ChildProcess::fake();

        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->post('/logs/start')
            ->assertOk()
            ->assertJson(['status' => 'started']);

        ChildProcess::assertStarted(
            fn ($cmd, $alias, $cwd, $env, $persistent) => $alias === 'tail'
                && is_array($cmd)
                && $cmd[0] === 'tail'
                && in_array('-F', $cmd, true)
                && str_ends_with((string) end($cmd), '/storage/logs/laravel.log'),
        );
    }

    public function test_stop_stops_the_tail_process(): void
    {
        ChildProcess::fake();

        $this->post('/logs/stop')
            ->assertOk()
            ->assertJson(['status' => 'stopped']);

        ChildProcess::assertStop('tail');
    }
}
