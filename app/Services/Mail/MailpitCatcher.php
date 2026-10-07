<?php

namespace App\Services\Mail;

/** Mailpit (https://mailpit.axllent.org) — also what Herd and Sail ship. */
class MailpitCatcher extends HttpCatcher
{
    public function kind(): string
    {
        return 'mailpit';
    }

    public function label(): string
    {
        return 'Mailpit';
    }

    public function messages(int $limit = 200): array
    {
        $data = $this->http()->get('/api/v1/messages', ['limit' => $limit])->json();

        return array_map(fn (array $m) => $this->summary($m), $data['messages'] ?? []);
    }

    public function message(string $id): array
    {
        // Fetching a message is also what marks it read in Mailpit.
        $m = $this->http()->get('/api/v1/message/'.rawurlencode($id))->json();

        return [
            ...$this->summary($m + ['Read' => true, 'Created' => $m['Date'] ?? null]),
            'cc' => $this->addresses($m['Cc'] ?? []),
            'html' => ($m['HTML'] ?? '') !== '' ? $m['HTML'] : null,
            'text' => ($m['Text'] ?? '') !== '' ? $m['Text'] : null,
            'attachments' => array_map(fn (array $a) => [
                'name' => (string) ($a['FileName'] ?? ''),
                'contentType' => (string) ($a['ContentType'] ?? ''),
                'size' => (int) ($a['Size'] ?? 0),
            ], $m['Attachments'] ?? []),
        ];
    }

    public function raw(string $id): string
    {
        return $this->http()->accept('*/*')->get('/api/v1/message/'.rawurlencode($id).'/raw')->body();
    }

    public function deleteAll(): void
    {
        $this->http()->delete('/api/v1/messages');
    }

    public function live(): array
    {
        return ['type' => 'websocket', 'url' => preg_replace('/^http/', 'ws', $this->url).'/api/events'];
    }

    private function summary(array $m): array
    {
        $from = $m['From'] ?? [];

        return [
            'id' => (string) ($m['ID'] ?? ''),
            'subject' => (string) ($m['Subject'] ?? ''),
            'from' => self::address($from['Name'] ?? null, $from['Address'] ?? null),
            'to' => $this->addresses($m['To'] ?? []),
            'date' => self::date($m['Created'] ?? null),
            'read' => (bool) ($m['Read'] ?? false),
            'snippet' => self::snippet($m['Snippet'] ?? ''),
        ];
    }

    private function addresses(?array $list): array
    {
        return array_map(fn (array $a) => self::address($a['Name'] ?? null, $a['Address'] ?? null), $list ?? []);
    }
}
