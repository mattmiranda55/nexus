<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Models\Snippet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnippetTest extends TestCase
{
    use RefreshDatabase;

    private function activateProject(string $name = 'demo'): Project
    {
        $project = Project::create(['name' => $name, 'path' => "/tmp/{$name}"]);
        Setting::current()->update(['active_project_id' => $project->id]);

        return $project;
    }

    public function test_index_returns_empty_list_without_snippets(): void
    {
        $this->getJson('/snippets')->assertOk()->assertJson(['snippets' => []]);
    }

    public function test_store_scopes_to_the_active_project_by_default(): void
    {
        $project = $this->activateProject();

        $this->postJson('/snippets', ['name' => 'count users', 'code' => 'User::count();'])
            ->assertOk()
            ->assertJsonPath('snippet.project_id', $project->id);

        $this->assertDatabaseHas('snippets', ['name' => 'count users', 'project_id' => $project->id]);
    }

    public function test_store_global_saves_without_a_project(): void
    {
        $this->activateProject();

        $this->postJson('/snippets', ['name' => 'clear cache', 'code' => 'Cache::flush();', 'global' => true])
            ->assertOk()
            ->assertJsonPath('snippet.project_id', null);
    }

    public function test_same_name_in_same_scope_overwrites(): void
    {
        $this->activateProject();

        $this->postJson('/snippets', ['name' => 'q', 'code' => 'v1;']);
        $this->postJson('/snippets', ['name' => 'q', 'code' => 'v2;']);

        $this->assertSame(1, Snippet::count());
        $this->assertSame('v2;', Snippet::first()->code);
    }

    public function test_index_shows_globals_plus_own_but_not_other_projects(): void
    {
        $mine = $this->activateProject('mine');
        $other = Project::create(['name' => 'other', 'path' => '/tmp/other']);

        Snippet::create(['project_id' => null, 'name' => 'global', 'code' => '1;']);
        Snippet::create(['project_id' => $mine->id, 'name' => 'own', 'code' => '2;']);
        Snippet::create(['project_id' => $other->id, 'name' => 'foreign', 'code' => '3;']);

        $names = collect($this->getJson('/snippets')->assertOk()->json('snippets'))->pluck('name');

        $this->assertEqualsCanonicalizing(['global', 'own'], $names->all());
    }

    public function test_destroy_deletes_the_snippet(): void
    {
        $snippet = Snippet::create(['project_id' => null, 'name' => 'x', 'code' => '1;']);

        $this->deleteJson("/snippets/{$snippet->id}")->assertOk();

        $this->assertDatabaseMissing('snippets', ['id' => $snippet->id]);
    }

    public function test_store_requires_name_and_code(): void
    {
        $this->activateProject();

        $this->postJson('/snippets', ['name' => ''])->assertStatus(422);
        $this->postJson('/snippets', ['name' => 'ok'])->assertStatus(422);
    }
}
