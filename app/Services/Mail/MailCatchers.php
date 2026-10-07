<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Finds the mail catchers running on this machine and remembers which one the
 * inbox reads from.
 *
 * Detection probes each candidate URL with a fingerprint request that only one
 * kind answers (Mailpit and MailHog share port 8025, so the port alone says
 * nothing). Probes are loopback with sub-second timeouts — they run inside a
 * request on the single-threaded PHP server.
 *
 * The chosen catcher is stored on the settings row as {kind, url, smtpHost,
 * smtpPort}, so ordinary inbox requests rebuild it without probing.
 */
class MailCatchers
{
    /** Preference when several are running: an installed service first. */
    public const PRIORITY = ['smtp4dev', 'mailpit', 'mailhog'];

    /** @return list<MailCatcher> in PRIORITY order, without duplicates */
    public function detect(): array
    {
        $found = [];

        foreach ($this->candidates() as [$url, $kinds]) {
            $catcher = $this->identify($url, $kinds);
            if ($catcher && ! isset($found[self::id($catcher)])) {
                $found[self::id($catcher)] = $catcher;
            }
        }

        $found = array_values($found);
        usort($found, fn ($a, $b) => array_search($a->kind(), self::PRIORITY) <=> array_search($b->kind(), self::PRIORITY));

        return $found;
    }

    /**
     * Which of the running catchers the inbox should read: the one pinned in
     * Settings while it's running, else the first by priority. Pinning never
     * leaves the inbox empty while something else is up.
     *
     * @param  list<MailCatcher>  $running
     */
    public function pick(array $running): ?MailCatcher
    {
        $pin = Setting::current()->mail_pin;

        foreach ($running as $catcher) {
            if ($pin !== null && self::id($catcher) === $pin) {
                return $catcher;
            }
        }

        return $running[0] ?? null;
    }

    /** The catcher the inbox is reading from, or null if none was chosen. */
    public function active(): ?MailCatcher
    {
        $source = Setting::current()->mail_source;

        return is_array($source) ? $this->make($source) : null;
    }

    public function choose(?MailCatcher $catcher): void
    {
        Setting::current()->update(['mail_source' => $catcher ? [
            'kind' => $catcher->kind(),
            'url' => $catcher->url(),
            'smtpHost' => $catcher->smtpHost(),
            'smtpPort' => $catcher->smtpPort(),
        ] : null]);
    }

    public static function id(MailCatcher $catcher): string
    {
        return $catcher->kind().'|'.$catcher->url();
    }

    /** @return array{id: string, kind: string, label: string, url: string, smtpHost: string, smtpPort: int} */
    public static function describe(MailCatcher $catcher): array
    {
        return [
            'id' => self::id($catcher),
            'kind' => $catcher->kind(),
            'label' => $catcher->label(),
            'url' => $catcher->url(),
            'smtpHost' => $catcher->smtpHost(),
            'smtpPort' => $catcher->smtpPort(),
        ];
    }

    /**
     * Where to look, and which kinds can be there. A URL set in Settings can
     * be any of them; the defaults are each kind's usual address.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function candidates(): array
    {
        $mailpit = 'http://'.config('nexus.mailpit.host').':'.config('nexus.mailpit.http_port');

        return array_values(array_filter([
            ($override = Setting::current()->mail_url) ? [$override, self::PRIORITY] : null,
            [rtrim((string) config('nexus.smtp4dev.url'), '/'), ['smtp4dev']],
            [$mailpit, ['mailpit', 'mailhog']],
        ]));
    }

    /** @param  list<string>  $kinds */
    private function identify(string $url, array $kinds): ?MailCatcher
    {
        $url = rtrim($url, '/');
        $host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';

        foreach ($kinds as $kind) {
            $catcher = match ($kind) {
                'smtp4dev' => $this->probeSmtp4dev($url, $host),
                'mailpit' => $this->probeMailpit($url, $host),
                'mailhog' => $this->probeMailHog($url, $host),
                default => null,
            };

            if ($catcher) {
                return $catcher;
            }
        }

        return null;
    }

    private function probeSmtp4dev(string $url, string $host): ?MailCatcher
    {
        $version = $this->probe($url.'/api/version');
        if (! isset($version['version'], $version['infoVersion'])) {
            return null;
        }

        // The SMTP port is configurable (25 by default); the API reports it.
        $server = $this->probe($url.'/api/server');

        return new Smtp4devCatcher($url, $host, (int) ($server['port'] ?? 25));
    }

    private function probeMailpit(string $url, string $host): ?MailCatcher
    {
        // /webui rather than /info: /info can make Mailpit check GitHub for
        // a newer version, and a probe shouldn't cause outbound traffic.
        $ui = $this->probe($url.'/api/v1/webui');
        $info = $ui === null ? $this->probe($url.'/api/v1/info') : null;

        if (! (is_array($ui) && array_key_exists('MessageRelay', $ui)) && ! isset($info['Version'], $info['Database'])) {
            return null;
        }

        // Mailpit doesn't expose its SMTP port; assume the configured one.
        return new MailpitCatcher($url, $host, (int) config('nexus.mailpit.smtp_port'));
    }

    private function probeMailHog(string $url, string $host): ?MailCatcher
    {
        $list = $this->probe($url.'/api/v2/messages?limit=1');
        if (! isset($list['items'], $list['total'])) {
            return null;
        }

        return new MailHogCatcher($url, $host, (int) config('nexus.mailpit.smtp_port'));
    }

    /** JSON from a quick GET, or null on any failure. */
    private function probe(string $url): ?array
    {
        try {
            $response = Http::connectTimeout(0.25)->timeout(0.75)->get($url);
        } catch (\Throwable) {
            return null;
        }

        // MailHog answers with Content-Type text/json, so decode regardless.
        $data = $response->successful() ? json_decode($response->body(), true) : null;

        return is_array($data) ? $data : null;
    }

    /** @param  array{kind?: string, url?: string, smtpHost?: string, smtpPort?: int}  $source */
    private function make(array $source): ?MailCatcher
    {
        $args = [(string) ($source['url'] ?? ''), (string) ($source['smtpHost'] ?? '127.0.0.1'), (int) ($source['smtpPort'] ?? 1025)];

        return match ($source['kind'] ?? null) {
            'smtp4dev' => new Smtp4devCatcher(...$args),
            'mailpit' => new MailpitCatcher(...$args),
            'mailhog' => new MailHogCatcher(...$args),
            default => null,
        };
    }
}
