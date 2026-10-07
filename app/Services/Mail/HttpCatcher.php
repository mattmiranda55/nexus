<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Shared plumbing for catchers spoken to over HTTP. */
abstract class HttpCatcher implements MailCatcher
{
    public function __construct(
        protected string $url,
        protected string $smtpHost,
        protected int $smtpPort,
    ) {
        $this->url = rtrim($url, '/');
    }

    public function url(): string
    {
        return $this->url;
    }

    public function smtpHost(): string
    {
        return $this->smtpHost;
    }

    public function smtpPort(): int
    {
        return $this->smtpPort;
    }

    /**
     * Short timeouts: these run inside a request on the single-threaded PHP
     * server, and a catcher on loopback answers in milliseconds or not at all.
     */
    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->url)->connectTimeout(1)->timeout(5)->acceptJson()->throw();
    }

    /** {name, address} from loose parts, with empty strings rather than nulls. */
    protected static function address(?string $name, ?string $address): array
    {
        return ['name' => trim((string) $name, " \"'"), 'address' => trim((string) $address)];
    }

    /** ISO 8601, or null when the catcher's value can't be parsed. */
    protected static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /** A plain-text preview: whitespace collapsed, capped. */
    protected static function snippet(?string $text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
