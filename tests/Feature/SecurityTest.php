<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_loopback_hosts_are_served(): void
    {
        // Absolute URLs: the test client derives the Host header from the URL.
        foreach (['localhost', 'localhost:8100', '127.0.0.1:8000', '[::1]:8000', 'nexus.localhost'] as $host) {
            $this->get("http://{$host}/")->assertOk();
        }
    }

    public function test_a_rebound_foreign_host_is_refused(): void
    {
        // DNS rebinding: the page is same-origin with us, but its Host isn't.
        $this->postJson('http://attacker.example:8000/tinker', ['code' => '1'])
            ->assertStatus(421);

        $this->get('http://127.0.0.1.attacker.example/')->assertStatus(421);
    }

    public function test_json_endpoints_answer_validation_errors_with_json(): void
    {
        $this->postJson('/tinker', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_only_allowlisted_links_can_be_opened(): void
    {
        $shell = Shell::fake();

        $this->postJson('/links/mailpit')->assertOk();
        $shell->assertOpenedExternal('https://mailpit.axllent.org');

        $this->postJson('/links/somewhere-else')->assertNotFound();
    }

    public function test_editor_open_rejects_control_characters_in_the_path(): void
    {
        Shell::fake();

        $this->postJson('/editor/open', ['file' => "/app/Foo.php\nmalicious", 'line' => 1])
            ->assertUnprocessable();
    }
}
