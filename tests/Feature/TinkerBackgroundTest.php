<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Run;
use App\Models\Setting;
use App\Services\TinkerJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Native\Desktop\Facades\ChildProcess;
use Tests\TestCase;

/**
 * Inside the desktop app, POST /tinker hands the run to a `nexus:tinker-run`
 * ChildProcess so the single-threaded PHP server stays free.
 */
class TinkerBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '0b6c1d2e-3f40-4a5b-8c6d-7e8f90a1b2c3';

    private string $dir;

    private TinkerJobs $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-jobs-'.uniqid();
        $this->jobs = new TinkerJobs($this->dir);
        $this->app->instance(TinkerJobs::class, $this->jobs);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function activeProject(): Project
    {
        // This app is itself a Laravel project, so point Tinker at it.
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        return $project;
    }

    public function test_in_the_desktop_app_a_run_is_queued_and_handed_to_a_worker(): void
    {
        config(['nativephp-internal.running' => true]);
        $fake = ChildProcess::fake();
        $project = $this->activeProject();

        $this->postJson('/tinker', ['code' => '2+3;', 'id' => strtoupper(self::ID)])
            ->assertStatus(202)
            ->assertJson(['status' => 'running', 'id' => self::ID]);

        $fake->assertArtisan(fn (array $cmd, string $alias, ...$options) => $cmd === ['nexus:tinker-run', self::ID]
            && $alias === 'tinker-'.self::ID);

        $this->assertSame(['project_id' => $project->id, 'code' => '2+3;'], $this->jobs->job(self::ID));
        $this->assertSame(0, Run::count(), 'The worker records the run, not the request');
    }

    public function test_the_server_picks_an_id_when_the_client_sends_none(): void
    {
        config(['nativephp-internal.running' => true]);
        ChildProcess::fake();
        $this->activeProject();

        $id = $this->postJson('/tinker', ['code' => '1'])->assertStatus(202)->json('id');

        $this->assertTrue(TinkerJobs::isValidId($id));
        $this->assertNotNull($this->jobs->job($id));
    }

    public function test_a_reused_id_is_rejected(): void
    {
        config(['nativephp-internal.running' => true]);
        ChildProcess::fake();
        $this->activeProject();

        $this->postJson('/tinker', ['code' => '1', 'id' => self::ID])->assertStatus(202);
        $this->postJson('/tinker', ['code' => '2', 'id' => self::ID])->assertStatus(409);
    }

    public function test_a_non_uuid_id_is_rejected(): void
    {
        config(['nativephp-internal.running' => true]);
        ChildProcess::fake();
        $this->activeProject();

        $this->postJson('/tinker', ['code' => '1', 'id' => '../../.env'])->assertUnprocessable();
    }

    public function test_outside_the_desktop_app_the_run_happens_inline(): void
    {
        $fake = ChildProcess::fake();
        $this->activeProject();

        $this->postJson('/tinker', ['code' => '2+3;', 'id' => self::ID])
            ->assertOk()
            ->assertJson(['output' => '5']);

        $this->assertSame([], $fake->artisans);
        $this->assertNull($this->jobs->job(self::ID));
    }

    public function test_the_worker_runs_the_job_records_it_and_stores_the_result(): void
    {
        $project = $this->activeProject();
        $this->jobs->create(self::ID, ['project_id' => $project->id, 'code' => "echo 'hi'; 2 + 3"]);

        $this->artisan('nexus:tinker-run', ['id' => self::ID])
            ->expectsOutput(TinkerJobs::DONE)
            ->assertSuccessful();

        $result = $this->jobs->result(self::ID);
        $this->assertSame("hi\n5", $result['raw']);
        $this->assertSame(5, $result['envelope']['root']['value']);
        $this->assertTrue(Run::sole()->ok);
    }

    public function test_the_worker_still_leaves_a_result_when_the_project_is_gone(): void
    {
        $this->jobs->create(self::ID, ['project_id' => 999, 'code' => '1']);

        $this->artisan('nexus:tinker-run', ['id' => self::ID])->assertSuccessful();

        $this->assertStringContainsString('project was removed', $this->jobs->result(self::ID)['raw']);
    }

    public function test_the_worker_rejects_an_unknown_run(): void
    {
        $this->artisan('nexus:tinker-run', ['id' => self::ID])->assertFailed();
    }

    public function test_a_finished_result_is_returned_once(): void
    {
        $this->jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        $this->jobs->complete(self::ID, ['envelope' => null, 'raw' => '1', 'output' => '1', 'loggedDuringRun' => null]);

        $this->getJson('/tinker/'.self::ID)->assertOk()->assertJson(['raw' => '1']);
        $this->getJson('/tinker/'.self::ID)->assertNotFound();
    }

    public function test_a_run_in_progress_answers_202(): void
    {
        $this->jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);

        $this->getJson('/tinker/'.self::ID)->assertStatus(202)->assertJson(['status' => 'running']);
    }

    public function test_a_worker_that_exited_without_a_result_is_reported(): void
    {
        $this->jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);

        $this->getJson('/tinker/'.self::ID.'?exited=1')
            ->assertOk()
            ->assertJson(['envelope' => null])
            ->assertJsonPath('raw', 'Error: The tinker worker stopped without returning a result.');

        $this->assertNull($this->jobs->job(self::ID));
    }

    public function test_a_silent_worker_is_given_up_on(): void
    {
        $this->jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        touch($this->dir.DIRECTORY_SEPARATOR.self::ID.'.job.json', time() - TinkerJobs::STALE_AFTER - 1);
        clearstatcache();

        $this->getJson('/tinker/'.self::ID)->assertOk()->assertJson(['envelope' => null]);
    }

    public function test_stopping_a_run_kills_the_worker_and_records_a_failed_run(): void
    {
        $fake = ChildProcess::fake();
        $project = $this->activeProject();
        $this->jobs->create(self::ID, ['project_id' => $project->id, 'code' => 'sleep(30);']);

        $this->deleteJson('/tinker/'.self::ID)
            ->assertOk()
            ->assertJson(['envelope' => null, 'raw' => 'Error: Run stopped.', 'stopped' => true]);

        $fake->assertStop('tinker-'.self::ID);
        $this->assertFalse($this->jobs->exists(self::ID));
        $this->assertSame('sleep(30);', Run::sole()->code);
        $this->assertFalse(Run::sole()->ok);
    }

    public function test_a_run_that_finished_before_the_stop_returns_its_result(): void
    {
        ChildProcess::fake();
        $this->jobs->create(self::ID, ['project_id' => 1, 'code' => '1']);
        $this->jobs->complete(self::ID, ['envelope' => null, 'raw' => '1', 'output' => '1', 'loggedDuringRun' => null]);

        $this->deleteJson('/tinker/'.self::ID)->assertOk()->assertJson(['raw' => '1'])->assertJsonMissing(['stopped' => true]);
        $this->assertSame(0, Run::count(), 'The worker already recorded it');
    }

    public function test_stopping_an_unknown_run_is_a_404(): void
    {
        ChildProcess::fake();

        $this->deleteJson('/tinker/'.self::ID)->assertNotFound();
    }

    public function test_result_ids_must_be_uuids(): void
    {
        $this->getJson('/tinker/not-a-uuid')->assertNotFound();
    }
}
