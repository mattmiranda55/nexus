<?php

namespace App\Services;

/**
 * Reads and repairs a project's `.env` so it points at Nexus-managed services
 * (Mailpit SMTP). Line-based edits that preserve everything else in the file.
 */
class EnvWriter
{
    /**
     * The current MAIL_* values, plus whether they already point at Mailpit's
     * SMTP port.
     *
     * @return array{exists: bool, connected: bool, values: array<string, ?string>}
     */
    public function mailStatus(string $projectPath, int $smtpPort): array
    {
        $path = $this->envPath($projectPath);
        if (! is_file($path)) {
            return ['exists' => false, 'connected' => false, 'values' => []];
        }

        $contents = (string) file_get_contents($path);
        $values = [];
        foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $key) {
            $values[$key] = $this->read($contents, $key);
        }

        $connected = $values['MAIL_MAILER'] === 'smtp'
            && (int) $values['MAIL_PORT'] === $smtpPort;

        return ['exists' => true, 'connected' => $connected, 'values' => $values];
    }

    /**
     * Point the project's mail config at Mailpit, writing only the keys that
     * are missing or wrong.
     *
     * @return array{ok: bool, changed: array<int, string>, error: ?string}
     */
    public function connectMailpit(string $projectPath, string $host, int $smtpPort): array
    {
        $path = $this->envPath($projectPath);
        if (! is_file($path)) {
            return ['ok' => false, 'changed' => [], 'error' => 'No .env file found in project'];
        }

        return $this->apply($path, [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $host,
            'MAIL_PORT' => (string) $smtpPort,
            'MAIL_USERNAME' => 'null',
            'MAIL_PASSWORD' => 'null',
        ]);
    }

    /**
     * Write only the keys that are missing or wrong, preserving the rest.
     *
     * @param  array<string, string>  $desired
     * @return array{ok: bool, changed: array<int, string>, error: ?string}
     */
    private function apply(string $path, array $desired): array
    {
        $contents = (string) file_get_contents($path);

        $changed = [];
        foreach ($desired as $key => $value) {
            if ($this->read($contents, $key) !== $value) {
                $contents = $this->write($contents, $key, $value);
                $changed[] = $key;
            }
        }

        if ($changed !== [] && file_put_contents($path, $contents) === false) {
            return ['ok' => false, 'changed' => [], 'error' => 'Could not write .env'];
        }

        return ['ok' => true, 'changed' => $changed, 'error' => null];
    }

    private function envPath(string $projectPath): string
    {
        return rtrim($projectPath, '/\\').DIRECTORY_SEPARATOR.'.env';
    }

    /**
     * Value of KEY=..., or null if absent. Strips surrounding quotes.
     *
     * The trailing \r matters: on a CRLF .env (the norm on Windows, and on any
     * checkout with core.autocrlf=true) `$` matches before the \n, so the
     * capture keeps the \r. Leaving it in makes every comparison here fail —
     * "smtp\r" !== "smtp" — which reads as "never connected, always rewrite".
     */
    private function read(string $contents, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $m)) {
            return null;
        }

        return trim($m[1], "\"' \t\r");
    }

    /** Replace KEY=... in place, or append it if the key isn't present. */
    private function write(string $contents, string $key, string $value): string
    {
        // Match whatever the file already uses rather than imposing \n on a
        // CRLF file and leaving it with mixed endings.
        $crlf = str_contains($contents, "\r\n");
        $eol = $crlf ? "\r\n" : "\n";
        $line = "{$key}={$value}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents)) {
            // Callback, not a replacement string: values may contain $ or \,
            // which preg_replace would treat as backreferences.
            $replacement = $line.($crlf ? "\r" : '');

            return preg_replace_callback($pattern, fn () => $replacement, $contents, 1);
        }

        return rtrim($contents, "\r\n").$eol.$line.$eol;
    }
}
