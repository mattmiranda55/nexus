<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;

/**
 * MailHog (https://github.com/mailhog/MailHog), v1.0.1 — unmaintained since
 * 2022 but still common. Same ports as Mailpit (SMTP 1025, HTTP 8025).
 *
 * Its API returns MIME still transfer-encoded, so bodies come from parsing the
 * raw message. It has no read/unread state either; Nexus keeps a small list
 * of the ids you've opened so the inbox can still show what's new.
 */
class MailHogCatcher extends HttpCatcher
{
    private const READ_KEY = 'nexus.mailhog.read';

    private const READ_KEEP = 1000;

    public function __construct(string $url, string $smtpHost, int $smtpPort, private MimeParser $parser = new MimeParser)
    {
        parent::__construct($url, $smtpHost, $smtpPort);
    }

    public function kind(): string
    {
        return 'mailhog';
    }

    public function label(): string
    {
        return 'MailHog';
    }

    public function messages(int $limit = 200): array
    {
        // v2 caps the limit at 250 and lists newest first.
        $data = $this->http()->get('/api/v2/messages', ['start' => 0, 'limit' => min($limit, 250)])->json();
        $read = $this->readIds();

        return array_map(function (array $m) use ($read) {
            $headers = $m['Content']['Headers'] ?? [];
            $id = (string) ($m['ID'] ?? '');

            return [
                'id' => $id,
                'subject' => MimeParser::decodeHeader((string) ($headers['Subject'][0] ?? '')),
                'from' => MimeParser::address((string) ($headers['From'][0] ?? $this->path($m['From'] ?? []))),
                'to' => MimeParser::addressList(implode(', ', $headers['To'] ?? []))
                    ?: array_map(fn ($p) => MimeParser::address($this->path($p)), $m['To'] ?? []),
                'date' => self::date($m['Created'] ?? ($headers['Date'][0] ?? null)),
                'read' => isset($read[$id]),
                'snippet' => '',
            ];
        }, $data['items'] ?? []);
    }

    public function message(string $id): array
    {
        $parsed = $this->parser->parse($this->raw($id));
        $h = $parsed['headers'];
        $this->markRead($id);

        return [
            'id' => $id,
            'subject' => (string) ($h['subject'][0] ?? ''),
            'from' => MimeParser::address((string) ($h['from'][0] ?? '')),
            'to' => MimeParser::addressList(implode(', ', $h['to'] ?? [])),
            'date' => self::date($h['date'][0] ?? null),
            'read' => true,
            'snippet' => self::snippet($parsed['text'] ?? strip_tags((string) $parsed['html'])),
            'cc' => MimeParser::addressList(implode(', ', $h['cc'] ?? [])),
            'html' => $parsed['html'],
            'text' => $parsed['text'],
            'attachments' => $parsed['attachments'],
        ];
    }

    public function raw(string $id): string
    {
        return $this->http()->accept('*/*')->get('/api/v1/messages/'.rawurlencode($id).'/download')->body();
    }

    public function deleteAll(): void
    {
        $this->http()->delete('/api/v1/messages');
        Cache::forget(self::READ_KEY);
    }

    public function live(): array
    {
        return ['type' => 'websocket', 'url' => preg_replace('/^http/', 'ws', $this->url).'/api/v2/websocket'];
    }

    /** MailHog's {Mailbox, Domain} envelope address as "mailbox@domain". */
    private function path(array $path): string
    {
        return trim(($path['Mailbox'] ?? '').'@'.($path['Domain'] ?? ''), '@');
    }

    /** @return array<string, true> */
    private function readIds(): array
    {
        return array_fill_keys(Cache::get(self::READ_KEY, []), true);
    }

    private function markRead(string $id): void
    {
        $ids = array_values(array_unique([$id, ...Cache::get(self::READ_KEY, [])]));
        Cache::forever(self::READ_KEY, array_slice($ids, 0, self::READ_KEEP));
    }
}
