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

    public function test_reads_a_crlf_env_without_trapping_the_carriage_return(): void
    {
        // Windows checkouts (and core.autocrlf=true anywhere) produce CRLF .env
        // files. The \r used to survive into the value, so MAIL_MAILER read as
        // "smtp\r" and the mail hub reported "not connected" forever.
        $this->writeEnv("APP_NAME=Demo\r\nMAIL_MAILER=smtp\r\nMAIL_HOST=127.0.0.1\r\nMAIL_PORT=1025\r\n");

        $status = (new EnvWriter)->mailStatus($this->dir, 1025);

        $this->assertSame('smtp', $status['values']['MAIL_MAILER']);
        $this->assertTrue($status['connected']);
    }

    public function test_connect_is_idempotent_on_a_crlf_env(): void
    {
        $this->writeEnv("MAIL_MAILER=smtp\r\nMAIL_HOST=127.0.0.1\r\nMAIL_PORT=1025\r\nMAIL_USERNAME=null\r\nMAIL_PASSWORD=null\r\n");

        $result = (new EnvWriter)->connectMailpit($this->dir, '127.0.0.1', 1025);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['changed']);
    }

    public function test_writing_to_a_crlf_env_preserves_crlf_endings(): void
    {
        $this->writeEnv("APP_NAME=Demo\r\nMAIL_MAILER=log\r\n");

        (new EnvWriter)->connectMailpit($this->dir, '127.0.0.1', 1025);
        $env = file_get_contents($this->dir.'/.env');

        $this->assertStringContainsString("MAIL_MAILER=smtp\r\n", $env);
        $this->assertStringContainsString("MAIL_PORT=1025\r\n", $env);
        // No bare LF should have crept in alongside the CRLFs.
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $env));
    }

    public function test_values_containing_regex_specials_are_written_literally(): void
    {
        // MAIL_HOST must already exist so this takes the in-place replace path,
        // where a naive preg_replace would expand "$1" as a backreference.
        $this->writeEnv("MAIL_MAILER=log\nMAIL_HOST=127.0.0.1\n");

        (new EnvWriter)->connectMailpit($this->dir, 'host$1\\x', 1025);

        $this->assertStringContainsString('MAIL_HOST=host$1\\x', file_get_contents($this->dir.'/.env'));
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
