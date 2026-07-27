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

        $suffix = implode(DIRECTORY_SEPARATOR, ['', 'storage', 'logs', 'laravel.log']);

        ChildProcess::assertStarted(
            fn ($cmd, $alias, $cwd, $env, $persistent) => $alias === 'tail'
                && is_array($cmd)
                && $cmd !== []
                // Windows routes through a shell (see LogTailCommand), so only
                // assert on the bare-tail shape where that's what we build.
                && (PHP_OS_FAMILY === 'Windows' || (
                    $cmd[0] === 'tail'
                    && in_array('-F', $cmd, true)
                    && str_ends_with((string) end($cmd), $suffix)
                )),
        );
    }

    public function test_start_reports_the_strategy_it_used(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Which strategy succeeds there depends on what's installed; the
            // per-strategy argv is covered by LogTailCommandTest instead.
            $this->markTestSkipped('Strategy is environment-dependent on Windows.');
        }

        ChildProcess::fake();

        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->post('/logs/start')->assertOk()->assertJson(['strategy' => 'tail']);
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
