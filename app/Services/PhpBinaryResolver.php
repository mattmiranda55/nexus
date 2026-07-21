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
        $projectPath = rtrim($projectPath, '/');
        $candidates = [];

        // 1) Project-local shims (Herd preferred)
        $candidates[] = "{$projectPath}/.herd/bin/php";
        $candidates[] = "{$projectPath}/.config/herd/bin/php";

        // 2) Project vendor-provided php (non-Herd)
        $candidates[] = "{$projectPath}/vendor/bin/php";

        // 3) User-level Herd installation
        if ($home = $this->homeDir()) {
            $candidates[] = "{$home}/.config/herd/bin/php";
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

    private function homeDir(): ?string
    {
        return $_SERVER['HOME']
            ?? getenv('HOME')
            ?: ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: null);
    }
}
