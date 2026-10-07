<?php

namespace Tests\Unit;

use App\Services\TargetEnvironment;
use PHPUnit\Framework\TestCase;

class TargetEnvironmentTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        $this->envFile = sys_get_temp_dir().'/nexus-target-env-'.uniqid().'.env';
        file_put_contents($this->envFile, "DB_CONNECTION=sqlite\nMAIL_MAILER=log\n# a comment\nAPP_NAME=\"Nexus\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
    }

    public function test_every_key_from_nexus_own_env_file_is_removed(): void
    {
        $env = (new TargetEnvironment($this->envFile, []))->isolate();

        $this->assertFalse($env['DB_CONNECTION']);
        $this->assertFalse($env['MAIL_MAILER']);
        $this->assertFalse($env['APP_NAME']);
    }

    public function test_laravel_bootstrap_overrides_are_removed_even_without_an_env_file(): void
    {
        $env = (new TargetEnvironment('/nonexistent/.env', []))->isolate();

        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'LARAVEL_STORAGE_PATH', 'APP_ENV', 'APP_KEY'] as $key) {
            $this->assertArrayHasKey($key, $env);
            $this->assertFalse($env[$key]);
        }
    }

    public function test_nativephp_and_nexus_variables_are_removed(): void
    {
        $env = (new TargetEnvironment('/nonexistent/.env', [
            'NATIVEPHP_RUNNING' => 'true',
            'NEXUS_PHP_PATH' => '/usr/bin/php',
            'PATH' => '/usr/bin',
            'HOME' => '/home/dev',
        ]))->isolate();

        $this->assertFalse($env['NATIVEPHP_RUNNING']);
        $this->assertFalse($env['NEXUS_PHP_PATH']);
    }

    public function test_ordinary_system_variables_are_untouched(): void
    {
        $env = (new TargetEnvironment($this->envFile, ['PATH' => '/usr/bin', 'HOME' => '/home/dev']))->isolate();

        $this->assertArrayNotHasKey('PATH', $env);
        $this->assertArrayNotHasKey('HOME', $env);
    }
}
