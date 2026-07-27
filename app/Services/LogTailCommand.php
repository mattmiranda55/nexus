<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Builds the argv for streaming a project's log file.
 *
 * Unix has `tail -F` and that is the whole story. Windows has no tail at all,
 * so we borrow whichever POSIX-ish shell the developer already has installed.
 * Git Bash and WSL both ship a real GNU tail, which follows the *path* and so
 * survives log rotation; PowerShell's `Get-Content -Wait` follows the open file
 * *handle* instead, meaning a rotated log silently stops streaming with no
 * error. Hence the ordering — and the warning the Settings dropdown shows.
 *
 * The OS family is injectable so the per-platform argv can be unit-tested from
 * a Mac.
 */
class LogTailCommand
{
    /** Lines of backfill before following. */
    public const LINES = 200;

    /** Windows shell strategies, best first. */
    public const STRATEGIES = ['gitbash', 'wsl', 'powershell'];

    public const DEFAULT_STRATEGY = 'gitbash';

    private string $osFamily;

    public function __construct(?string $osFamily = null)
    {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
    }

    public function isWindows(): bool
    {
        return $this->osFamily === 'Windows';
    }

    /**
     * The argv to hand to ChildProcess, or a failure explaining what's missing.
     * `strategy` is ignored off Windows, where there's only ever plain tail.
     *
     * @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string}
     */
    public function build(string $path, ?string $strategy = null): array
    {
        if (! $this->isWindows()) {
            return $this->ok(['tail', '-n', (string) self::LINES, '-F', $path], 'tail');
        }

        return match ($this->normalize($strategy)) {
            'wsl' => $this->wsl($path),
            'powershell' => $this->powershell($path),
            default => $this->gitBash($path),
        };
    }

    /** Coerce anything unrecognised (null, stale value, junk) to the default. */
    public function normalize(?string $strategy): string
    {
        $strategy = strtolower(trim((string) $strategy));

        return in_array($strategy, self::STRATEGIES, true) ? $strategy : self::DEFAULT_STRATEGY;
    }

    /**
     * Git for Windows' bash. Deliberately does NOT trust a bare `bash` on PATH
     * first: Windows ships `C:\Windows\System32\bash.exe`, which is the WSL
     * launcher, not Git Bash — running our command through it would silently
     * become the WSL strategy with an unconverted path.
     */
    public function findGitBash(): ?string
    {
        $candidates = [];

        if ($override = trim((string) env('NEXUS_GIT_BASH_PATH'))) {
            $candidates[] = $override;
        }

        foreach ($this->programFilesDirs() as $dir) {
            $candidates[] = $dir.'\\Git\\bin\\bash.exe';
        }

        if ($found = (new ExecutableFinder)->find('bash')) {
            if (! $this->isWslLauncher($found)) {
                $candidates[] = $found;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert a Windows path to the MSYS form Git Bash understands:
     * `C:\app\storage\logs\laravel.log` -> `/c/app/storage/logs/laravel.log`.
     * Already-POSIX and UNC paths pass through untouched.
     */
    public function toMsysPath(string $path): string
    {
        return $this->toUnixPath($path, '/');
    }

    /** Same, but WSL's mount prefix: `C:\app\x` -> `/mnt/c/app/x`. */
    public function toWslPath(string $path): string
    {
        return $this->toUnixPath($path, '/mnt/');
    }

    /** @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string} */
    private function gitBash(string $path): array
    {
        $bash = $this->findGitBash();

        if ($bash === null) {
            return $this->fail(
                'gitbash',
                'Git Bash was not found. Install Git for Windows, set NEXUS_GIT_BASH_PATH, '
                .'or choose a different log shell in Settings.',
            );
        }

        // -c rather than -lc: we want the tail, not the user's login profile.
        return $this->ok([
            $bash,
            '-c',
            sprintf('tail -n %d -F %s', self::LINES, $this->shellQuote($this->toMsysPath($path))),
        ], 'gitbash');
    }

    /** @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string} */
    private function wsl(string $path): array
    {
        // wsl.exe forwards argv directly, so no shell quoting is involved.
        return $this->ok([
            'wsl.exe',
            'tail',
            '-n',
            (string) self::LINES,
            '-F',
            $this->toWslPath($path),
        ], 'wsl');
    }

    /** @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string} */
    private function powershell(string $path): array
    {
        // Force UTF-8 on the way out: the default console encoding mangles any
        // non-ASCII in log messages before it ever reaches the renderer.
        $script = sprintf(
            '[Console]::OutputEncoding=[Text.Encoding]::UTF8; Get-Content -LiteralPath %s -Tail %d -Wait',
            $this->psQuote($path),
            self::LINES,
        );

        return $this->ok([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            $script,
        ], 'powershell');
    }

    private function toUnixPath(string $path, string $prefix): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^([A-Za-z]):/(.*)$#', $path, $m)) {
            return $prefix.strtolower($m[1]).'/'.$m[2];
        }

        return $path;
    }

    /** Single-quote for POSIX sh, escaping any embedded single quote. */
    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** Single-quote for PowerShell, where the escape is a doubled quote. */
    private function psQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /** @return array<int, string> */
    private function programFilesDirs(): array
    {
        $dirs = [];

        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432'] as $var) {
            if ($value = getenv($var)) {
                $dirs[] = rtrim($value, '\\/');
            }
        }

        if ($local = getenv('LOCALAPPDATA')) {
            $dirs[] = rtrim($local, '\\/').'\\Programs';
        }

        return array_values(array_unique($dirs ?: ['C:\\Program Files']));
    }

    private function isWslLauncher(string $path): bool
    {
        return str_contains(strtolower(str_replace('/', '\\', $path)), '\\system32\\');
    }

    /**
     * @param  array<int, string>  $argv
     * @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string}
     */
    private function ok(array $argv, string $strategy): array
    {
        return ['ok' => true, 'argv' => $argv, 'strategy' => $strategy, 'error' => null];
    }

    /** @return array{ok: bool, argv: array<int, string>, strategy: string, error: ?string} */
    private function fail(string $strategy, string $error): array
    {
        return ['ok' => false, 'argv' => [], 'strategy' => $strategy, 'error' => $error];
    }
}
