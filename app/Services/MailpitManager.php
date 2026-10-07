<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Mail\MailCatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\ChildProcess;

/**
 * Nexus's own Mailpit, the fallback mail catcher. Nothing is bundled: the user
 * downloads it from Settings into Nexus's storage (or points
 * NEXUS_MAILPIT_PATH at one), then chooses whether Nexus starts it or it
 * starts at login. Whatever mail server the user already runs always comes
 * first — see MailCatchers and startWithNexus().
 */
class MailpitManager
{
    private const ALIAS = 'mailpit';

    public const DOWNLOAD_ALIAS = 'mailpit-download';

    private string $host;

    private int $smtpPort;

    private int $httpPort;

    public function __construct()
    {
        $this->host = (string) config('nexus.mailpit.host', '127.0.0.1');
        $this->smtpPort = (int) config('nexus.mailpit.smtp_port', 1025);
        $this->httpPort = (int) config('nexus.mailpit.http_port', 8025);
    }

    public function apiUrl(): string
    {
        return "http://{$this->host}:{$this->httpPort}";
    }

    public function smtpPort(): int
    {
        return $this->smtpPort;
    }

    /**
     * Is a Mailpit answering on the API port right now?
     *
     * Kept deliberately short. The app is served by PHP's built-in server,
     * which handles one request at a time, so every millisecond spent waiting
     * here is a millisecond the whole UI is unresponsive. This is a loopback
     * check: if something is listening it answers in single-digit ms, and if
     * nothing is the connection is refused outright. The only case that can
     * actually burn the budget is a firewall silently dropping the packet —
     * which is exactly the case we don't want to block on.
     */
    public function detect(): bool
    {
        try {
            return Http::connectTimeout(0.25)
                ->timeout(0.5)
                ->get($this->apiUrl().'/api/v1/info')
                ->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * "Start with Nexus": run our Mailpit when the user chose that mode and no
     * other mail server is running. Called with what detection just found.
     *
     * @param  list<MailCatcher>  $running
     */
    public function startWithNexus(array $running): bool
    {
        if ($running !== [] || Setting::current()->mailpit_mode !== 'nexus' || $this->resolveBinary() === null) {
            return false;
        }

        return $this->start() === 'starting';
    }

    /**
     * Launch Nexus's Mailpit for this session, unless one is already
     * answering. The caller polls detection afterwards: a freshly spawned
     * process needs a moment to bind.
     *
     * @return 'running'|'starting'|'missing'|'unavailable'
     */
    public function start(): string
    {
        if ($this->detect()) {
            return 'running';
        }

        $binary = $this->resolveBinary();
        if ($binary === null) {
            return 'missing';
        }

        // Outside the desktop runtime there is no Electron to host the child
        // process, and the facade throws.
        try {
            ChildProcess::stop(self::ALIAS);
            ChildProcess::start($this->arguments($binary), self::ALIAS);
        } catch (\Throwable) {
            return 'unavailable';
        }

        return 'starting';
    }

    /** The command line that runs Mailpit on Nexus's configured ports. */
    public function arguments(string $binary): array
    {
        return [
            $binary,
            '--smtp', "{$this->host}:{$this->smtpPort}",
            '--listen', "{$this->host}:{$this->httpPort}",
        ];
    }

    /**
     * Download Mailpit into storage (scripts/fetch-mailpit.mjs, checksum
     * verified) as a ChildProcess; the UI waits for it to exit. False when
     * there's no desktop runtime to run it.
     */
    public function download(): bool
    {
        try {
            ChildProcess::node([
                base_path('scripts'.DIRECTORY_SEPARATOR.'fetch-mailpit.mjs'),
                '--dest='.$this->downloadDir(),
            ], self::DOWNLOAD_ALIAS);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** Where runtime downloads go: per-user storage, writable in a packaged app. */
    public function downloadDir(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'mailpit');
    }

    /** Stop and delete the downloaded Mailpit. False if it couldn't be deleted (still running, on Windows). */
    public function remove(): bool
    {
        $this->stop();

        return File::deleteDirectory($this->downloadDir()) || ! is_dir($this->downloadDir());
    }

    /** The downloaded version (fetch-mailpit.mjs writes it), if any. */
    public function version(): ?string
    {
        $file = $this->downloadDir().DIRECTORY_SEPARATOR.'VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) ?: null : null;
    }

    public function stop(): void
    {
        try {
            ChildProcess::stop(self::ALIAS);
        } catch (\Throwable) {
            // Nothing running, or no desktop runtime to ask.
        }
    }

    /**
     * Locate Nexus's Mailpit: the NEXUS_MAILPIT_PATH override, else the copy
     * downloaded from Settings. Null if neither exists.
     */
    public function resolveBinary(): ?string
    {
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        $candidates = array_filter([
            config('nexus.mailpit.path'),
            $this->downloadDir().DIRECTORY_SEPARATOR.$this->platformDir().DIRECTORY_SEPARATOR."mailpit{$ext}",
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** OS → the download's per-platform subdirectory (fetch-mailpit.mjs's layout). */
    public function platformDir(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'mac',
            'Windows' => 'win',
            default => 'linux',
        };
    }
}
