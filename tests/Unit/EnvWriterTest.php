<?php

namespace Tests\Unit;

use App\Services\EnvWriter;
use PHPUnit\Framework\TestCase;

class EnvWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/nexus-env-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->dir.'/.env', $contents);
    }

    public function test_reports_connected_when_already_pointed_at_mailpit(): void
    {
        $this->writeEnv("APP_NAME=Demo\nMAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\n");

        $status = (new EnvWriter)->mailStatus($this->dir, 1025);

        $this->assertTrue($status['exists']);
        $this->assertTrue($status['connected']);
        $this->assertSame('smtp', $status['values']['MAIL_MAILER']);
    }

    public function test_reports_not_connected_for_other_mailer(): void
    {
        $this->writeEnv("MAIL_MAILER=log\nMAIL_PORT=25\n");

        $this->assertFalse((new EnvWriter)->mailStatus($this->dir, 1025)['connected']);
    }

    public function test_connect_repairs_only_wrong_keys_and_preserves_the_rest(): void
    {
        $this->writeEnv("APP_NAME=Demo\nMAIL_MAILER=log\nMAIL_PORT=25\nOTHER=keep\n");

        $result = (new EnvWriter)->connectMailpit($this->dir, '127.0.0.1', 1025);
        $env = file_get_contents($this->dir.'/.env');

        $this->assertTrue($result['ok']);
        $this->assertContains('MAIL_MAILER', $result['changed']);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('MAIL_PORT=1025', $env);
        $this->assertStringContainsString('OTHER=keep', $env);   // untouched
        $this->assertStringContainsString('APP_NAME=Demo', $env); // untouched
    }

    public function test_connect_appends_missing_keys(): void
    {
        $this->writeEnv("APP_NAME=Demo\n");

        (new EnvWriter)->connectMailpit($this->dir, '127.0.0.1', 1025);
        $env = file_get_contents($this->dir.'/.env');

        $this->assertStringContainsString('MAIL_HOST=127.0.0.1', $env);
        $this->assertStringContainsString('MAIL_USERNAME=null', $env);
    }

    public function test_connect_fails_without_an_env_file(): void
    {
        $result = (new EnvWriter)->connectMailpit($this->dir, '127.0.0.1', 1025);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }
}
