<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
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

    public function test_tinker_endpoint_requires_an_active_project(): void
    {
        $this->post('/tinker', ['code' => '2+3;'])
            ->assertStatus(422)
            ->assertJson(['output' => 'Error: No project selected']);
    }
}
