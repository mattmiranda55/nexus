<?php

namespace App\Services\Mail;

/**
 * A local development mail catcher (Mailpit, smtp4dev, MailHog, …) read
 * through its HTTP API, so Nexus can show the mail in its own inbox instead of
 * the catcher's web UI.
 *
 * Every method returns Nexus's own normalized shapes, never the catcher's, so
 * the frontend has a single message format:
 *
 *   summary: {id, subject, from: {name, address}, to: [{name, address}],
 *             date: ?string (ISO 8601), read: bool, snippet: string}
 *   detail:  summary + {cc: [...], html: ?string, text: ?string,
 *             attachments: [{name, contentType, size}]}
 *
 * Calls throw (connection or HTTP errors) and the controller turns that into
 * a 502; implementations don't swallow failures.
 */
interface MailCatcher
{
    /** Stable machine name: mailpit | smtp4dev | mailhog. */
    public function kind(): string;

    /** Human name for the UI. */
    public function label(): string;

    /** The catcher's API base URL, without a trailing slash. */
    public function url(): string;

    /** Where apps should send mail: the host and SMTP port to wire into .env. */
    public function smtpHost(): string;

    public function smtpPort(): int;

    /** @return list<array<string, mixed>> newest first */
    public function messages(int $limit = 200): array;

    /** @return array<string, mixed> */
    public function message(string $id): array;

    /** The message's raw RFC 822 source. */
    public function raw(string $id): string;

    public function deleteAll(): void;

    /**
     * How the renderer can hear about new mail directly from the catcher,
     * keeping the single-threaded PHP server out of it:
     * {type: 'websocket'|'signalr'|'poll', url: ?string}.
     *
     * @return array{type: string, url: ?string}
     */
    public function live(): array;
}
