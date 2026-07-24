<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\ChildProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Bundles & manages Mailpit (a single static Go binary) and acts as a thin
 * client over its HTTP API. We never own SMTP/MIME — Mailpit does.
 *
 * Lifecycle: detect an already-running instance first (Herd's, Sail's, a
 * manually-started one) and reuse it; only launch our bundled binary when
 * nothing is listening. This keeps the native-first setups fast and avoids
 * fighting the user's environment.
 */
class MailpitManager
{
    private const ALIAS = 'mailpit';

    private string $host;

    private int $smtpPort;

    private int $httpPort;

    public function __construct()
    {
        $this->host = env('NEXUS_MAILPIT_HOST', '127.0.0.1');
        $this->smtpPort = (int) env('NEXUS_MAILPIT_SMTP_PORT', 1025);
        $this->httpPort = (int) env('NEXUS_MAILPIT_HTTP_PORT', 8025);
    }

    public function apiUrl(): string
    {
        return "http://{$this->host}:{$this->httpPort}";
    }

    public function smtpPort(): int
    {
        return $this->smtpPort;
    }

    /** Is a Mailpit answering on the API port right now? */
    public function detect(): bool
    {
        try {
            return Http::timeout(1)->get($this->apiUrl().'/api/v1/info')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ensure a Mailpit is available: reuse a detected one, else launch the
     * bundled binary. The caller (frontend) polls status afterwards since a
     * freshly-spawned process needs a moment to bind.
     *
     * @return array{running: bool, source: string, apiUrl: string, binary: ?string}
     */
    public function ensureRunning(): array
    {
        if ($this->detect()) {
            return $this->state(true, 'detected');
        }

        $binary = $this->resolveBinary();
        if ($binary === null) {
            return $this->state(false, 'missing');
        }

        ChildProcess::stop(self::ALIAS);
        ChildProcess::start([
            $binary,
            '--smtp', "{$this->host}:{$this->smtpPort}",
            '--listen', "{$this->host}:{$this->httpPort}",
        ], self::ALIAS);

        return $this->state(false, 'starting', $binary);
    }

    public function stop(): void
    {
        ChildProcess::stop(self::ALIAS);
    }

    public function status(): array
    {
        $running = $this->detect();

        return $this->state($running, $running ? 'detected' : 'down');
    }

    /**
     * Locate the Mailpit binary: explicit override → PATH (Herd etc.) →
     * the per-OS binary bundled under resources/bin. Null if none found.
     */
    public function resolveBinary(): ?string
    {
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        $candidates = array_filter([
            env('NEXUS_MAILPIT_PATH'),
            (new ExecutableFinder)->find('mailpit'),
            base_path("resources/bin/mailpit/{$this->platformDir()}/mailpit{$ext}"),
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Pure OS → bundled-binary subdirectory mapping (unit-tested). */
    public function platformDir(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'mac',
            'Windows' => 'win',
            default => 'linux',
        };
    }

    private function state(bool $running, string $source, ?string $binary = null): array
    {
        return [
            'running' => $running,
            'source' => $source, // detected | starting | missing | down
            'apiUrl' => $this->apiUrl(),
            'binary' => $binary,
        ];
    }
}
