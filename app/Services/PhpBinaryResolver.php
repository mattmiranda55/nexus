<?php

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Locates the PHP executable to run a project's Tinker with — preferring the
 * PHP that belongs to the project (Herd/local) over anything global.
 *
 * Ported from the original Go resolvePHPBinary() 6-tier fallback.
 */
class PhpBinaryResolver
{
    public function resolve(string $projectPath): string
    {
        $projectPath = rtrim($projectPath, '/\\');
        $candidates = [];

        // 1) Project-local shims (Herd preferred)
        array_push($candidates, ...$this->variants("{$projectPath}/.herd/bin/php"));
        array_push($candidates, ...$this->variants("{$projectPath}/.config/herd/bin/php"));

        // 2) Project vendor-provided php (non-Herd)
        array_push($candidates, ...$this->variants("{$projectPath}/vendor/bin/php"));

        // 3) User-level Herd installation
        if ($home = $this->homeDir()) {
            array_push($candidates, ...$this->variants("{$home}/.config/herd/bin/php"));
        }

        // 4) OS-specific Herd bundle path
        if (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = '/Applications/Herd.app/Contents/Resources/bin/php';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            if ($localAppData = getenv('LOCALAPPDATA')) {
                $candidates[] = rtrim($localAppData, '\\/').'\\Herd\\bin\\php.exe';
            }
            $candidates[] = 'C:\\Program Files\\Herd\\bin\\php.exe';
        }

        // 5) Explicit overrides (Settings, then env var)
        if ($override = Setting::current()->php_path) {
            $candidates[] = $override;
        }
        if ($env = trim((string) getenv('NEXUS_PHP_PATH'))) {
            $candidates[] = $env;
        }

        // 6) Fallback to PATH
        if ($onPath = (new ExecutableFinder)->find('php')) {
            $candidates[] = $onPath;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Unable to find PHP executable; set NEXUS_PHP_PATH or configure an explicit binary in Settings.'
        );
    }

    /**
     * Executable spellings for a suffix-less base path. Windows needs the
     * extension spelled out — `is_file('…/bin/php')` is false there even when
     * `php.exe` (or a Herd `.bat` shim) sits right next to it.
     *
     * @return array<int, string>
     */
    private function variants(string $base): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? ["{$base}.exe", "{$base}.bat", "{$base}.cmd"]
            : [$base];
    }

    private function homeDir(): ?string
    {
        return $_SERVER['HOME']
            ?? getenv('HOME')
            ?: ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: null);
    }
}
