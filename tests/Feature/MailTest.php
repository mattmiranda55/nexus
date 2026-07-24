<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_proxied_from_mailpit(): void
    {
        Http::fake([
            '*/api/v1/messages*' => Http::response([
                'total' => 1,
                'messages' => [['ID' => 'abc', 'Subject' => 'Hi', 'From' => ['Address' => 'a@b.c']]],
            ]),
        ]);

        $this->getJson('/mail/messages')
            ->assertOk()
            ->assertJsonPath('messages.0.Subject', 'Hi');
    }

    public function test_a_single_message_is_proxied_and_id_is_sanitised(): void
    {
        Http::fake(['*/api/v1/message/abc123*' => Http::response(['ID' => 'abc123', 'HTML' => '<p>hi</p>'])]);

        // The slashes/dots should be stripped before hitting Mailpit.
        $this->getJson('/mail/message/abc123')
            ->assertOk()
            ->assertJsonPath('HTML', '<p>hi</p>');
    }

    public function test_unreachable_mailpit_reports_a_gateway_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->getJson('/mail/messages')
            ->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_connect_wires_the_active_project_env(): void
    {
        $dir = sys_get_temp_dir().'/nexus-mailtest-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_NAME=Demo\nMAIL_MAILER=log\n");

        $project = Project::create(['name' => 'demo', 'path' => $dir]);
        Setting::current()->update(['active_project_id' => $project->id]);

        $this->post('/mail/connect')->assertOk()->assertJson(['ok' => true]);

        $this->assertStringContainsString('MAIL_MAILER=smtp', file_get_contents($dir.'/.env'));

        @unlink($dir.'/.env');
        @rmdir($dir);
    }
}
