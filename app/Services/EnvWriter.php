<?php

namespace App\Services;

/**
 * Reads and repairs a project's `.env` so it points at Nexus-managed services
 * (Mailpit SMTP, the dump receiver). Line-based edits that preserve everything
 * else in the file.
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
     * Whether the project's dump() output is routed to the Nexus dump server.
     * VAR_DUMPER_SERVER may legitimately be absent: var-dumper defaults it to
     * 127.0.0.1:9912, so `format=server` alone counts when we host the default.
     *
     * @return array{exists: bool, connected: bool, values: array<string, ?string>}
     */
    public function dumpStatus(string $projectPath, string $server): array
    {
        $path = $this->envPath($projectPath);
        if (! is_file($path)) {
            return ['exists' => false, 'connected' => false, 'values' => []];
        }

        $contents = (string) file_get_contents($path);
        $values = [];
        foreach (['VAR_DUMPER_FORMAT', 'VAR_DUMPER_SERVER'] as $key) {
            $values[$key] = $this->read($contents, $key);
        }

        $target = $values['VAR_DUMPER_SERVER'] ?? '127.0.0.1:9912';
        $connected = $values['VAR_DUMPER_FORMAT'] === 'server' && $target === $server;

        return ['exists' => true, 'connected' => $connected, 'values' => $values];
    }

    /**
     * Route the project's dump()/dd() calls to the Nexus dump server. Safe to
     * leave in place: var-dumper falls back to normal in-page dumps whenever
     * the server isn't running.
     *
     * @return array{ok: bool, changed: array<int, string>, error: ?string}
     */
    public function connectDumps(string $projectPath, string $server): array
    {
        $path = $this->envPath($projectPath);
        if (! is_file($path)) {
            return ['ok' => false, 'changed' => [], 'error' => 'No .env file found in project'];
        }

        return $this->apply($path, [
            'VAR_DUMPER_FORMAT' => 'server',
            'VAR_DUMPER_SERVER' => $server,
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
        return rtrim($projectPath, '/').'/.env';
    }

    /** Value of KEY=..., or null if absent. Strips surrounding quotes. */
    private function read(string $contents, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $m)) {
            return null;
        }

        return trim($m[1], "\"' \t");
    }

    /** Replace KEY=... in place, or append it if the key isn't present. */
    private function write(string $contents, string $key, string $value): string
    {
        $line = "{$key}={$value}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents)) {
            return preg_replace($pattern, $line, $contents, 1);
        }

        return rtrim($contents, "\n")."\n".$line."\n";
    }
}
