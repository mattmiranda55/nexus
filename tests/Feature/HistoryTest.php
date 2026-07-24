<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Run;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase;

    private function activateProject(string $name = 'demo'): Project
    {
        $project = Project::create(['name' => $name, 'path' => "/tmp/{$name}"]);
        Setting::current()->update(['active_project_id' => $project->id]);

        return $project;
    }

    public function test_index_is_empty_without_an_active_project(): void
    {
        $this->getJson('/history')->assertOk()->assertJson(['runs' => []]);
    }

    public function test_index_returns_the_active_projects_runs_newest_first(): void
    {
        $project = $this->activateProject();
        $other = Project::create(['name' => 'other', 'path' => '/tmp/other']);

        Run::record($project->id, 'first;', true, 10);
        Run::record($project->id, 'second;', false, 20);
        Run::record($other->id, 'foreign;', true, 30);

        $runs = $this->getJson('/history')->assertOk()->json('runs');

        $this->assertSame(['second;', 'first;'], array_column($runs, 'code'));
        $this->assertFalse($runs[0]['ok']);
    }

    public function test_record_prunes_history_beyond_the_cap(): void
    {
        $project = $this->activateProject();

        foreach (range(1, Run::KEEP + 5) as $i) {
            Run::record($project->id, "run {$i};", true, 1);
        }

        $this->assertSame(Run::KEEP, Run::where('project_id', $project->id)->count());
        // The oldest rows are the ones dropped.
        $this->assertSame('run 6;', Run::orderBy('id')->first()->code);
    }

    public function test_pruning_is_scoped_per_project(): void
    {
        $project = $this->activateProject();
        $other = Project::create(['name' => 'other', 'path' => '/tmp/other']);
        Run::record($other->id, 'keep me;', true, 1);

        foreach (range(1, Run::KEEP + 5) as $i) {
            Run::record($project->id, "run {$i};", true, 1);
        }

        $this->assertSame(1, Run::where('project_id', $other->id)->count());
    }

    public function test_clear_removes_only_the_active_projects_runs(): void
    {
        $project = $this->activateProject();
        $other = Project::create(['name' => 'other', 'path' => '/tmp/other']);

        Run::record($project->id, 'mine;', true, 1);
        Run::record($other->id, 'theirs;', true, 1);

        $this->deleteJson('/history')->assertOk();

        $this->assertSame(0, Run::where('project_id', $project->id)->count());
        $this->assertSame(1, Run::where('project_id', $other->id)->count());
    }
}
