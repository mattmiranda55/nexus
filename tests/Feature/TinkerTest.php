<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Run;
use App\Models\Setting;
use App\Services\TargetEnvironment;
use App\Services\TinkerOutputParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_extracts_result_line(): void
    {
        // Real piped-tinker shape captured on this machine.
        $raw = "> 2+3;\n\n> = 5\n  ";
        $this->assertSame('5', (new TinkerOutputParser)->parse($raw));
    }

    public function test_parser_returns_null_when_nothing_meaningful(): void
    {
        $this->assertSame('null', (new TinkerOutputParser)->parse("> \n\n  "));
    }

    public function test_parser_keeps_dump_style_output(): void
    {
        $raw = "> dump('hi');\n\"hi\"\n> = null\n";
        $this->assertStringContainsString('"hi"', (new TinkerOutputParser)->parse($raw));
    }

    public function test_tinker_endpoint_runs_real_code(): void
    {
        // This app is itself a Laravel project, so point Tinker at it.
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->post('/tinker', ['code' => '2+3;'])
            ->assertOk()
            ->assertJson(['output' => '5']);
    }

    public function test_tinker_endpoint_returns_structured_envelope(): void
    {
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $response = $this->post('/tinker', ['code' => "['id' => 1, 'name' => 'Ada'];"])
            ->assertOk();

        $envelope = $response->json('envelope');
        $this->assertNotNull($envelope, 'Expected a structured envelope from the real tinker run');
        $this->assertSame('assoc', $envelope['root']['kind']);
        $this->assertSame('name', $envelope['root']['entries'][1]['key']);
    }

    public function test_echo_output_reaches_the_raw_view(): void
    {
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->post('/tinker', ['code' => "echo 'hello'; 2 + 3"])
            ->assertOk()
            ->assertJsonPath('raw', "hello\n5")
            ->assertJsonPath('envelope.root.value', 5);
    }

    public function test_the_users_code_is_timed_apart_from_laravels_boot(): void
    {
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $response = $this->post('/tinker', ['code' => 'usleep(50000); 1'])->assertOk();

        $codeMs = $response->json('envelope.timing.ms');
        $this->assertGreaterThanOrEqual(50, $codeMs);
        $this->assertLessThan($response->json('durationMs'), $codeMs, 'The whole run includes booting Laravel');
        $this->assertGreaterThan(0, $response->json('envelope.timing.memoryPeak'));
        $this->assertSame('1', $response->json('raw'), 'The timing line stays out of the raw view');
    }

    public function test_an_exception_is_reported_and_recorded_as_a_failed_run(): void
    {
        $project = Project::create(['name' => 'self', 'path' => base_path()]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $response = $this->post('/tinker', ['code' => "throw new RuntimeException('boom');"])->assertOk();

        $this->assertNull($response->json('envelope'));
        $this->assertStringContainsString('boom', $response->json('raw'));
        $this->assertStringNotContainsString('null', $response->json('raw'));
        $this->assertFalse(Run::sole()->ok);
    }

    public function test_the_target_project_does_not_inherit_nexus_environment(): void
    {
        // A stand-in project whose "artisan" just reports what it inherited.
        $dir = sys_get_temp_dir().'/nexus-target-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/artisan', '<?php echo "= ".json_encode([getenv("NEXUS_LEAK_PROBE"), getenv("PATH") !== false]), PHP_EOL;');
        $envFile = $dir.'/nexus.env';
        file_put_contents($envFile, "NEXUS_LEAK_PROBE=nexus-value\n");

        putenv('NEXUS_LEAK_PROBE=nexus-value');
        $_ENV['NEXUS_LEAK_PROBE'] = 'nexus-value';
        $this->app->instance(TargetEnvironment::class, new TargetEnvironment($envFile));

        try {
            $project = Project::create(['name' => 'target', 'path' => $dir]);
            Setting::current()->update(['active_project_id' => $project->id]);

            // [leaked value, PATH still present]
            $this->post('/tinker', ['code' => '1'])
                ->assertOk()
                ->assertJsonPath('raw', '[false,true]');
        } finally {
            putenv('NEXUS_LEAK_PROBE');
            unset($_ENV['NEXUS_LEAK_PROBE']);
            @unlink($dir.'/artisan');
            @unlink($envFile);
            @rmdir($dir);
        }
    }

    public function test_tinker_endpoint_requires_an_active_project(): void
    {
        $this->post('/tinker', ['code' => '2+3;'])
            ->assertStatus(422)
            ->assertJson(['output' => 'Error: No project selected']);
    }
}
