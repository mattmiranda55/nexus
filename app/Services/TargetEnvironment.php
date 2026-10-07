<?php

namespace App\Services;

use Dotenv\Dotenv;

/**
 * The environment a target project's `php artisan tinker` must NOT inherit.
 *
 * Symfony Process hands a child the parent's whole environment, and Nexus's own
 * environment is full of Laravel variables: everything from Nexus's `.env`
 * (DB_CONNECTION, MAIL_MAILER, APP_KEY, ...) plus, inside the desktop runtime,
 * the bootstrap overrides Electron sets for Nexus's PHP (APP_CONFIG_CACHE,
 * LARAVEL_STORAGE_PATH, APP_ENV=production, ...). Laravel's dotenv loader never
 * overwrites a variable that already exists, so every one of those would beat
 * the target project's own `.env` — tinker would boot the target app against
 * Nexus's database, mailer, cached config and storage directory.
 *
 * The returned map sets each such key to false, which Symfony Process treats
 * as "remove from the child's environment".
 */
class TargetEnvironment
{
    /**
     * Read by Laravel's bootstrap itself, so a leaked value redirects the whole
     * app (its config cache, storage path, environment) rather than one setting.
     */
    private const BOOTSTRAP_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_KEY',
        'APP_URL',
        'APP_BASE_PATH',
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'APP_SERVICES_CACHE',
        'APP_PACKAGES_CACHE',
        'APP_EVENTS_CACHE',
        'LARAVEL_STORAGE_PATH',
    ];

    /** Nexus- and NativePHP-specific variables; meaningless to any other app. */
    private const PREFIXES = ['NATIVEPHP_', 'NEXUS_'];

    /**
     * @param  ?string  $envFile  Nexus's own .env (defaults to the app's).
     * @param  ?array<string, mixed>  $current  The current environment (defaults to the real one).
     */
    public function __construct(
        private ?string $envFile = null,
        private ?array $current = null,
    ) {}

    /** @return array<string, false> */
    public function isolate(): array
    {
        $keys = [...self::BOOTSTRAP_KEYS, ...$this->ownDotenvKeys()];

        foreach (array_keys($this->current()) as $key) {
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    $keys[] = (string) $key;
                }
            }
        }

        return array_fill_keys(array_values(array_unique($keys)), false);
    }

    /**
     * Keys declared in Nexus's own .env. When the config is cached (packaged
     * builds) Laravel never loads that file, so there is nothing to leak — but
     * stripping the keys anyway is harmless.
     *
     * @return array<int, string>
     */
    private function ownDotenvKeys(): array
    {
        $file = $this->envFile ?? app()->environmentFilePath();

        if (! is_file($file)) {
            return [];
        }

        try {
            return array_map('strval', array_keys(Dotenv::parse((string) file_get_contents($file))));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        return $this->current ?? ($_ENV + getenv());
    }
}
