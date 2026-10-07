<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Mail\MailCatchers;
use App\Services\Mail\MailHogCatcher;
use App\Services\Mail\MailpitCatcher;
use App\Services\Mail\Smtp4devCatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Detection and the per-catcher adapters, against faked HTTP shaped after
 * each server's real API (smtp4dev 3.x, Mailpit 1.x, MailHog 1.0.1).
 */
class MailCatchersTest extends TestCase
{
    use RefreshDatabase;

    private const SMTP4DEV = 'http://127.0.0.1:5000';

    private const PORT_8025 = 'http://127.0.0.1:8025';

    /** Everything refused except the given URL prefixes. */
    private function serve(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            foreach ($routes as $prefix => $response) {
                if (str_starts_with($request->url(), $prefix)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            throw new ConnectionException('refused');
        });
    }

    public function test_nothing_running_detects_nothing(): void
    {
        $this->serve([]);

        $this->assertSame([], app(MailCatchers::class)->detect());
    }

    public function test_smtp4dev_is_detected_with_the_smtp_port_it_reports(): void
    {
        $this->serve([
            self::SMTP4DEV.'/api/version' => Http::response(['version' => '3.6.0', 'infoVersion' => '3.6.0+abc']),
            self::SMTP4DEV.'/api/server' => Http::response(['port' => 2525, 'isRunning' => true]),
        ]);

        [$catcher] = app(MailCatchers::class)->detect();

        $this->assertInstanceOf(Smtp4devCatcher::class, $catcher);
        $this->assertSame(2525, $catcher->smtpPort());
        $this->assertSame('127.0.0.1', $catcher->smtpHost());
    }

    public function test_mailpit_is_detected_by_its_webui_endpoint(): void
    {
        $this->serve([self::PORT_8025.'/api/v1/webui' => Http::response(['Label' => '', 'MessageRelay' => ['Enabled' => false]])]);
        $this->assertInstanceOf(MailpitCatcher::class, app(MailCatchers::class)->detect()[0]);
    }

    public function test_mailhog_is_detected_by_its_v2_api(): void
    {
        $this->serve([self::PORT_8025.'/api/v2/messages' => Http::response('{"total":0,"count":0,"start":0,"items":[]}', 200, ['Content-Type' => 'text/json'])]);
        $this->assertInstanceOf(MailHogCatcher::class, app(MailCatchers::class)->detect()[0]);
    }

    public function test_an_unrelated_server_on_the_port_is_not_mistaken_for_a_catcher(): void
    {
        $this->serve([self::PORT_8025 => Http::response('<html>hello</html>'), self::SMTP4DEV => Http::response(['ok' => true])]);

        $this->assertSame([], app(MailCatchers::class)->detect());
    }

    public function test_smtp4dev_wins_when_several_are_running_and_a_settings_url_is_probed_too(): void
    {
        Setting::current()->update(['mail_url' => 'http://127.0.0.1:18025/']);
        $this->serve([
            self::SMTP4DEV.'/api/version' => Http::response(['version' => '3', 'infoVersion' => '3']),
            self::SMTP4DEV.'/api/server' => Http::response(['port' => 25]),
            'http://127.0.0.1:18025/api/v1/webui' => Http::response(['MessageRelay' => []]),
        ]);

        $kinds = array_map(fn ($c) => $c->kind().' '.$c->url(), app(MailCatchers::class)->detect());

        $this->assertSame(['smtp4dev '.self::SMTP4DEV, 'mailpit http://127.0.0.1:18025'], $kinds);
    }

    public function test_the_chosen_catcher_is_rebuilt_from_settings_without_probing(): void
    {
        $catchers = app(MailCatchers::class);
        $catchers->choose(new Smtp4devCatcher(self::SMTP4DEV, 'localhost', 2525));
        Http::fake();

        $active = $catchers->active();

        $this->assertInstanceOf(Smtp4devCatcher::class, $active);
        $this->assertSame([self::SMTP4DEV, 'localhost', 2525], [$active->url(), $active->smtpHost(), $active->smtpPort()]);
        Http::assertNothingSent();
    }

    public function test_smtp4dev_messages_are_normalized(): void
    {
        $this->serve([
            self::SMTP4DEV.'/api/mailboxes' => Http::response([['id' => 'x', 'name' => 'Default']]),
            self::SMTP4DEV.'/api/messages' => function (Request $request) {
                $this->assertSame('Default', $request['mailboxName']);
                $this->assertSame('200', (string) $request['pageSize']);

                return Http::response(['currentPage' => 1, 'pageCount' => 1, 'rowCount' => 1, 'results' => [[
                    'id' => 'a1b2', 'from' => '"Shop" <shop@example.test>', 'to' => ['jo@example.test'],
                    'receivedDate' => '2026-10-07T10:00:00Z', 'subject' => 'Order', 'isUnread' => true, 'attachmentCount' => 0,
                ]]]);
            },
        ]);

        $this->assertSame([[
            'id' => 'a1b2',
            'subject' => 'Order',
            'from' => ['name' => 'Shop', 'address' => 'shop@example.test'],
            'to' => [['name' => '', 'address' => 'jo@example.test']],
            'date' => '2026-10-07T10:00:00+00:00',
            'read' => false,
            'snippet' => '',
        ]], (new Smtp4devCatcher(self::SMTP4DEV, '127.0.0.1', 25))->messages());
    }

    public function test_smtp4dev_message_fetches_bodies_and_marks_it_read(): void
    {
        $this->serve([
            self::SMTP4DEV.'/api/messages/a1b2/html' => Http::response('<p>Hi</p>'),
            self::SMTP4DEV.'/api/messages/a1b2/plaintext' => Http::response('Hi'),
            self::SMTP4DEV.'/api/messages/a1b2/markRead' => Http::response(),
            self::SMTP4DEV.'/api/messages/a1b2' => Http::response([
                'id' => 'a1b2', 'from' => 'shop@example.test', 'to' => ['jo@example.test'], 'cc' => [],
                'receivedDate' => '2026-10-07T10:00:00Z', 'subject' => 'Order', 'hasHtmlBody' => true, 'hasPlainTextBody' => true,
                'parts' => [['name' => '', 'isAttachment' => false, 'size' => 10, 'headers' => [], 'childParts' => [
                    ['name' => 'invoice.pdf', 'isAttachment' => true, 'size' => 1234, 'childParts' => [],
                        'headers' => [['name' => 'Content-Type', 'value' => 'application/pdf; name=invoice.pdf']]],
                ]]],
            ]),
        ]);

        $message = (new Smtp4devCatcher(self::SMTP4DEV, '127.0.0.1', 25))->message('a1b2');

        $this->assertSame('<p>Hi</p>', $message['html']);
        $this->assertSame('Hi', $message['text']);
        $this->assertTrue($message['read']);
        $this->assertSame([['name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'size' => 1234]], $message['attachments']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/messages/a1b2/markRead'));
    }

    public function test_mailpit_messages_are_normalized(): void
    {
        $this->serve([self::PORT_8025.'/api/v1/messages' => Http::response(['total' => 1, 'messages' => [[
            'ID' => 'mp1', 'Read' => true, 'From' => ['Name' => 'Shop', 'Address' => 'shop@example.test'],
            'To' => [['Name' => '', 'Address' => 'jo@example.test']], 'Subject' => 'Order',
            'Created' => '2026-10-07T10:00:00.123Z', 'Snippet' => 'Thanks   for your order',
        ]]])]);

        $this->assertSame([[
            'id' => 'mp1',
            'subject' => 'Order',
            'from' => ['name' => 'Shop', 'address' => 'shop@example.test'],
            'to' => [['name' => '', 'address' => 'jo@example.test']],
            'date' => '2026-10-07T10:00:00+00:00',
            'read' => true,
            'snippet' => 'Thanks for your order',
        ]], (new MailpitCatcher(self::PORT_8025, '127.0.0.1', 1025))->messages());
    }

    public function test_mailhog_messages_come_from_headers_and_track_read_state_locally(): void
    {
        $raw = "From: Shop <shop@example.test>\r\nTo: jo@example.test\r\nSubject: =?utf-8?Q?Caf=C3=A9?=\r\n"
            ."Date: Tue, 07 Oct 2026 10:00:00 +0000\r\nContent-Type: text/plain\r\n\r\nHello";
        $this->serve([
            self::PORT_8025.'/api/v2/messages' => Http::response(json_encode(['total' => 1, 'count' => 1, 'start' => 0, 'items' => [[
                'ID' => 'xyz@mailhog.example',
                'From' => ['Mailbox' => 'shop', 'Domain' => 'example.test'],
                'To' => [['Mailbox' => 'jo', 'Domain' => 'example.test']],
                'Content' => ['Headers' => ['Subject' => ['=?utf-8?Q?Caf=C3=A9?='], 'From' => ['Shop <shop@example.test>'], 'To' => ['jo@example.test']]],
                'Created' => '2026-10-07T10:00:00.5Z',
            ]]]), 200, ['Content-Type' => 'text/json']),
            self::PORT_8025.'/api/v1/messages/xyz%40mailhog.example/download' => Http::response($raw),
        ]);
        $mailhog = new MailHogCatcher(self::PORT_8025, '127.0.0.1', 1025);

        [$summary] = $mailhog->messages();
        $this->assertSame('Café', $summary['subject']);
        $this->assertSame(['name' => 'Shop', 'address' => 'shop@example.test'], $summary['from']);
        $this->assertFalse($summary['read']);

        $message = $mailhog->message('xyz@mailhog.example');
        $this->assertSame('Hello', $message['text']);

        $this->assertTrue($mailhog->messages()[0]['read'], 'Opening it marks it read for next time');
    }

    public function test_live_endpoints_per_kind(): void
    {
        $this->assertSame(['type' => 'signalr', 'url' => 'ws://127.0.0.1:5000/hubs/notifications'], (new Smtp4devCatcher(self::SMTP4DEV, 'h', 25))->live());
        $this->assertSame(['type' => 'websocket', 'url' => 'ws://127.0.0.1:8025/api/events'], (new MailpitCatcher(self::PORT_8025, 'h', 1025))->live());
        $this->assertSame(['type' => 'websocket', 'url' => 'ws://127.0.0.1:8025/api/v2/websocket'], (new MailHogCatcher(self::PORT_8025, 'h', 1025))->live());
    }
}
