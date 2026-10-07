<?php

namespace App\Services\Mail;

/**
 * smtp4dev (https://github.com/rnwood/smtp4dev), v3.x. Often installed as a
 * Windows service; web/API on :5000 by default, SMTP on :25.
 *
 * Messages live in mailboxes (normally "Default"), and bodies come from
 * separate endpoints. Fetching a message doesn't mark it read — that's its
 * own call.
 */
class Smtp4devCatcher extends HttpCatcher
{
    private ?string $mailbox = null;

    public function kind(): string
    {
        return 'smtp4dev';
    }

    public function label(): string
    {
        return 'smtp4dev';
    }

    public function messages(int $limit = 200): array
    {
        $data = $this->http()->get('/api/messages', [
            'mailboxName' => $this->mailbox(),
            'folderName' => 'INBOX',
            'sortColumn' => 'receivedDate',
            'sortIsDescending' => 'true',
            'page' => 1,
            // The server's default page size is 5.
            'pageSize' => $limit,
        ])->json();

        // Paged ({results: […]}) since 2022; a bare array before that.
        $rows = array_is_list($data ?? []) ? ($data ?? []) : ($data['results'] ?? []);

        return array_map(fn (array $m) => $this->summary($m), $rows);
    }

    public function message(string $id): array
    {
        $path = '/api/messages/'.rawurlencode($id);
        $m = $this->http()->get($path)->json();

        $html = ($m['hasHtmlBody'] ?? false) ? $this->http()->accept('*/*')->get($path.'/html')->body() : null;
        $text = ($m['hasPlainTextBody'] ?? false) ? $this->http()->accept('*/*')->get($path.'/plaintext')->body() : null;

        // Best-effort: a failed mark-read shouldn't hide the message.
        try {
            $this->http()->post($path.'/markRead');
        } catch (\Throwable) {
            //
        }

        return [
            ...$this->summary($m + ['isUnread' => false]),
            'snippet' => self::snippet($text ?? strip_tags((string) $html)),
            'cc' => array_map(fn ($a) => MimeParser::address((string) $a), $m['cc'] ?? []),
            'html' => $html !== '' ? $html : null,
            'text' => $text !== '' ? $text : null,
            'attachments' => $this->attachments($m['parts'] ?? []),
        ];
    }

    public function raw(string $id): string
    {
        return $this->http()->accept('*/*')->get('/api/messages/'.rawurlencode($id).'/raw')->body();
    }

    public function deleteAll(): void
    {
        $this->http()->delete('/api/messages/*?mailboxName='.rawurlencode($this->mailbox()));
    }

    public function live(): array
    {
        return ['type' => 'signalr', 'url' => preg_replace('/^http/', 'ws', $this->url).'/hubs/notifications'];
    }

    /** "Default" when it exists (the usual case), else the first mailbox. */
    private function mailbox(): string
    {
        if ($this->mailbox !== null) {
            return $this->mailbox;
        }

        try {
            $names = array_column($this->http()->get('/api/mailboxes')->json() ?? [], 'name');
        } catch (\Throwable) {
            $names = [];
        }

        return $this->mailbox = in_array('Default', $names, true) || $names === [] ? 'Default' : (string) $names[0];
    }

    private function summary(array $m): array
    {
        return [
            'id' => (string) ($m['id'] ?? ''),
            'subject' => (string) ($m['subject'] ?? ''),
            'from' => MimeParser::address((string) ($m['from'] ?? '')),
            'to' => array_map(fn ($a) => MimeParser::address((string) $a), (array) ($m['to'] ?? [])),
            'date' => self::date($m['receivedDate'] ?? null),
            'read' => ! ($m['isUnread'] ?? false),
            'snippet' => '',
        ];
    }

    /** Attachments are flagged on MIME parts, which nest. */
    private function attachments(array $parts): array
    {
        $found = [];

        foreach ($parts as $part) {
            if ($part['isAttachment'] ?? false) {
                $type = '';
                foreach ($part['headers'] ?? [] as $header) {
                    if (strcasecmp((string) ($header['name'] ?? ''), 'Content-Type') === 0) {
                        $type = trim(explode(';', (string) $header['value'])[0]);
                    }
                }

                $found[] = [
                    'name' => (string) ($part['name'] ?? ''),
                    'contentType' => $type,
                    'size' => (int) ($part['size'] ?? 0),
                ];
            }

            array_push($found, ...$this->attachments($part['childParts'] ?? []));
        }

        return $found;
    }
}
