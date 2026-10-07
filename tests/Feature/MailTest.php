<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Services\Mail\MailCatchers;
use App\Services\Mail\MailpitCatcher;
use App\Services\Mail\Smtp4devCatcher;
use App\Services\MailpitAutostart;
use App\Services\MailpitManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\ChildProcess;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    private const MAILPIT = 'http://127.0.0.1:8025';

    private const SMTP4DEV = 'http://127.0.0.1:5000';

    protected function setUp(): void
    {
        parent::setUp();

        // Never touch the real login items from a test.
        $this->app->instance(MailpitAutostart::class, new MailpitAutostart(
            app(MailpitManager::class),
            home: sys_get_temp_dir().'/nexus-home-'.uniqid(),
            os: 'Darwin',
            run: fn () => true,
        ));
    }

    private function useMailpit(): void
    {
        app(MailCatchers::class)->choose(new MailpitCatcher(self::MAILPIT, '127.0.0.1', 1025));
    }

    private function tempProject(string $name, ?string $env): Project
    {
        $dir = sys_get_temp_dir().'/nexus-mailtest-'.uniqid();
        mkdir($dir);
        if ($env !== null) {
            file_put_contents($dir.'/.env', $env);
        }

        return Project::create(['name' => $name, 'path' => $dir]);
    }

    public function test_messages_are_read_from_the_active_catcher(): void
    {
        $this->useMailpit();
        Http::fake(['*/api/v1/messages*' => Http::response([
            'messages' => [['ID' => 'abc', 'Subject' => 'Hi', 'From' => ['Address' => 'a@b.c']]],
        ])]);

        $this->getJson('/mail/messages')
            ->assertOk()
            ->assertJsonPath('messages.0.subject', 'Hi')
            ->assertJsonPath('messages.0.from.address', 'a@b.c');
    }

    public function test_without_a_chosen_catcher_the_inbox_says_so(): void
    {
        Http::fake();

        $this->getJson('/mail/messages')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_a_single_message_is_fetched_and_its_id_sanitised(): void
    {
        $this->useMailpit();
        Http::fake(['*/api/v1/message/abc123' => Http::response(['ID' => 'abc123', 'HTML' => '<p>hi</p>'])]);

        $this->getJson('/mail/message/abc123')
            ->assertOk()
            ->assertJsonPath('html', '<p>hi</p>');
    }

    public function test_an_unreachable_catcher_is_a_gateway_error(): void
    {
        $this->useMailpit();
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->getJson('/mail/messages')
            ->assertStatus(502)
            ->assertJsonPath('error', "Mailpit isn't reachable at ".self::MAILPIT.'.');
    }

    public function test_an_id_that_sanitises_to_nothing_is_not_fetched(): void
    {
        $this->useMailpit();
        Http::fake();

        $this->getJson('/mail/message/'.rawurlencode('//'))->assertNotFound();
        $this->getJson('/mail/message/'.rawurlencode('<>').'/raw')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_failed_upstream_raw_fetch_is_a_gateway_error(): void
    {
        $this->useMailpit();
        Http::fake(['*/raw' => Http::response('not found', 404)]);

        $this->getJson('/mail/message/abc/raw')->assertStatus(502);
    }

    public function test_status_picks_the_detected_catcher_and_reports_wiring_against_it(): void
    {
        $wired = $this->tempProject('api', "MAIL_MAILER=smtp\nMAIL_PORT=2525\n");
        $other = $this->tempProject('blog', null);
        Http::fake(function (Request $request) {
            return match (true) {
                str_starts_with($request->url(), self::SMTP4DEV.'/api/version') => Http::response(['version' => '3', 'infoVersion' => '3']),
                str_starts_with($request->url(), self::SMTP4DEV.'/api/server') => Http::response(['port' => 2525]),
                default => throw new ConnectionException('refused'),
            };
        });

        $this->postJson('/mail/status')
            ->assertOk()
            ->assertJsonPath('active.kind', 'smtp4dev')
            ->assertJsonPath('active.smtpPort', 2525)
            ->assertJsonPath('sources.0.id', 'smtp4dev|'.self::SMTP4DEV)
            ->assertJsonPath('projects', [
                ['id' => $wired->id, 'name' => 'api', 'hasEnv' => true, 'connected' => true],
                ['id' => $other->id, 'name' => 'blog', 'hasEnv' => false, 'connected' => false],
            ]);

        $this->assertSame('smtp4dev', Setting::current()->mail_source['kind']);
    }

    public function test_status_with_nothing_running_offers_the_mailpit_fallback(): void
    {
        $this->useMailpit();
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->postJson('/mail/status')
            ->assertOk()
            ->assertJsonPath('sources', [])
            ->assertJsonPath('active', null)
            ->assertJsonPath('mailpit.loginSupported', true)
            ->assertJsonPath('mailpit.mode', 'off')
            ->assertJsonPath('mailpitStarting', false)
            ->assertJsonStructure(['mailpit' => ['installed', 'version']]);

        $this->assertNull(Setting::current()->mail_source, 'A catcher that went away is forgotten');
    }

    private function smtp4devAndMailpitRunning(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_starts_with($request->url(), self::SMTP4DEV.'/api/version') => Http::response(['version' => '3', 'infoVersion' => '3']),
                str_starts_with($request->url(), self::SMTP4DEV.'/api/server') => Http::response(['port' => 25]),
                str_starts_with($request->url(), self::MAILPIT.'/api/v1/webui') => Http::response(['MessageRelay' => []]),
                default => throw new ConnectionException('refused'),
            };
        });
    }

    public function test_a_server_pinned_in_settings_wins_while_it_runs(): void
    {
        $this->smtp4devAndMailpitRunning();
        $settings = ['theme' => 'dark', 'editor' => 'vscode'];

        $this->patch('/settings', $settings + ['mailPin' => 'mailpit|'.self::MAILPIT])->assertRedirect();
        $this->postJson('/mail/status')->assertJsonPath('active.kind', 'mailpit')->assertJsonPath('pin', 'mailpit|'.self::MAILPIT);

        $this->patch('/settings', $settings + ['mailPin' => ''])->assertRedirect();
        $this->postJson('/mail/status')->assertJsonPath('active.kind', 'smtp4dev');
    }

    public function test_a_pinned_server_that_stopped_falls_back_to_automatic(): void
    {
        $this->smtp4devAndMailpitRunning();
        Setting::current()->update(['mail_pin' => 'mailhog|http://127.0.0.1:9999']);

        $this->postJson('/mail/status')->assertJsonPath('active.kind', 'smtp4dev');
    }

    private function installedMailpit(): string
    {
        $dir = sys_get_temp_dir().'/nexus-mailpit-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/mailpit', 'binary');
        config(['nexus.mailpit.path' => $dir.'/mailpit']);

        return $dir;
    }

    public function test_start_with_nexus_runs_mailpit_only_when_nothing_else_is(): void
    {
        $this->installedMailpit();
        Setting::current()->update(['mailpit_mode' => 'nexus']);
        $processes = ChildProcess::fake();

        $this->smtp4devAndMailpitRunning();
        $this->postJson('/mail/status')->assertJsonPath('mailpitStarting', false);
        $this->assertSame([], $processes->starts, 'Your own mail server always wins');
    }

    public function test_start_with_nexus_starts_mailpit_when_nothing_is_running(): void
    {
        $this->installedMailpit();
        Setting::current()->update(['mailpit_mode' => 'nexus']);
        $processes = ChildProcess::fake();
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->postJson('/mail/status')->assertJsonPath('mailpitStarting', true);
        $processes->assertStarted(fn (array $cmd, string $alias, ...$rest) => $alias === 'mailpit');
    }

    public function test_choosing_a_mailpit_mode_requires_the_download(): void
    {
        config(['nexus.mailpit.path' => null]);
        $this->app->instance(MailpitManager::class, new class extends MailpitManager
        {
            public function downloadDir(): string
            {
                return sys_get_temp_dir().'/nexus-none-'.uniqid();
            }
        });

        $this->patch('/settings', ['theme' => 'dark', 'editor' => 'vscode', 'mailpitMode' => 'nexus'])
            ->assertSessionHasErrors(['mailpitMode' => 'Download Mailpit first.']);
        $this->assertSame('off', Setting::current()->mailpit_mode);
    }

    public function test_choosing_start_at_login_registers_it(): void
    {
        $this->installedMailpit();
        ChildProcess::fake();
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->patch('/settings', ['theme' => 'dark', 'editor' => 'vscode', 'mailpitMode' => 'login'])
            ->assertSessionHasNoErrors();

        $this->assertSame('login', Setting::current()->mailpit_mode);
        $this->assertTrue(app(MailpitAutostart::class)->enabled());
    }

    public function test_removing_mailpit_turns_its_mode_off(): void
    {
        $this->app->instance(MailpitManager::class, new class extends MailpitManager
        {
            public function downloadDir(): string
            {
                return sys_get_temp_dir().'/nexus-gone-'.uniqid();
            }
        });
        ChildProcess::fake();
        Setting::current()->update(['mailpit_mode' => 'nexus']);

        $this->deleteJson('/mail/mailpit')->assertOk();

        $this->assertSame('off', Setting::current()->mailpit_mode);
    }

    public function test_connect_wires_a_project_to_the_active_catchers_smtp_port(): void
    {
        app(MailCatchers::class)->choose(new Smtp4devCatcher(self::SMTP4DEV, '127.0.0.1', 25));
        $project = $this->tempProject('demo', "APP_NAME=Demo\nMAIL_MAILER=log\n");

        $this->post("/mail/connect/{$project->id}")->assertOk()->assertJson(['ok' => true]);

        $env = file_get_contents($project->path.'/.env');
        $this->assertStringContainsString('MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('MAIL_PORT=25', $env);
    }

    public function test_the_watcher_relays_the_active_catchers_live_events(): void
    {
        config(['nativephp-internal.running' => true]);
        $fake = ChildProcess::fake();
        app(MailCatchers::class)->choose(new Smtp4devCatcher(self::SMTP4DEV, '127.0.0.1', 25));

        $this->postJson('/mail/watch')->assertOk()->assertJson(['live' => true]);

        $fake->assertStop('mail-watch');
        $fake->assertNode(fn (array $cmd, string $alias, ...$rest) => $alias === 'mail-watch'
            && str_ends_with($cmd[0], 'mail-watch.mjs')
            && array_slice($cmd, 1) === ['signalr', 'ws://127.0.0.1:5000/hubs/notifications']);
    }

    public function test_outside_the_desktop_app_the_inbox_polls(): void
    {
        ChildProcess::fake();
        $this->useMailpit();

        $this->postJson('/mail/watch')->assertOk()->assertJson(['live' => false]);
    }

    public function test_the_global_mail_url_must_be_http_and_is_probed(): void
    {
        $settings = ['theme' => 'dark', 'editor' => 'vscode'];

        $this->patchJson('/settings', $settings + ['mailUrl' => 'file:///etc/passwd'])->assertUnprocessable();

        $this->patch('/settings', $settings + ['mailUrl' => 'http://127.0.0.1:18025/'])->assertRedirect();
        $this->assertSame('http://127.0.0.1:18025', Setting::current()->mail_url);

        Http::fake(fn () => Http::response('', 404));
        $this->postJson('/mail/status');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://127.0.0.1:18025/'));
    }
}
