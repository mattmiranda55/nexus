<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Events\Notifications\NotificationClicked;
use Native\Desktop\Facades\Window;
use Tests\TestCase;

class MailNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** The notification NativePHP sent to Electron, or null. */
    private function sentNotification(): ?array
    {
        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/notification'));

        return $sent->isEmpty() ? null : $sent->first()[0]->data();
    }

    public function test_one_new_message_names_its_sender_and_subject(): void
    {
        Http::fake(['*' => Http::response(['reference' => 'x'])]);

        $this->postJson('/mail/notify', ['count' => 1, 'id' => 'abc123', 'subject' => 'Invoice paid', 'from' => 'Acme'])
            ->assertOk()
            ->assertJson(['status' => 'sent']);

        $this->assertSame(
            ['New email from Acme', 'Invoice paid', 'nexus-mail:abc123'],
            [$this->sentNotification()['title'], $this->sentNotification()['body'], $this->sentNotification()['reference']],
        );
    }

    public function test_a_batch_is_summarized(): void
    {
        Http::fake(['*' => Http::response(['reference' => 'x'])]);

        $this->postJson('/mail/notify', ['count' => 3, 'id' => 'c', 'subject' => ''])->assertOk();

        $this->assertSame('3 new emails', $this->sentNotification()['title']);
        $this->assertSame('Latest: (no subject)', $this->sentNotification()['body']);
    }

    public function test_it_respects_the_setting(): void
    {
        Http::fake();
        Setting::current()->update(['notify_mail' => false]);

        $this->postJson('/mail/notify', ['count' => 1, 'id' => 'a'])->assertJson(['status' => 'disabled']);

        $this->assertNull($this->sentNotification());
    }

    public function test_the_setting_is_saved_from_settings(): void
    {
        $this->patch('/settings', ['theme' => 'dark', 'editor' => 'vscode', 'notifyMail' => false])->assertRedirect();

        $this->assertFalse(Setting::current()->notify_mail);
    }

    public function test_clicking_a_mail_notification_brings_the_window_forward(): void
    {
        $windows = Window::fake();

        event(new NotificationClicked('nexus-mail:abc123', '{}'));
        $windows->assertShown('main');
    }

    public function test_other_notifications_are_left_alone(): void
    {
        $windows = Window::fake();

        event(new NotificationClicked('1712.abc', '{}'));

        $this->assertSame([], $windows->shown);
    }
}
