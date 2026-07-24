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

    public function test_dump_status_connected_with_explicit_server(): void
    {
        $this->writeEnv("VAR_DUMPER_FORMAT=server\nVAR_DUMPER_SERVER=127.0.0.1:9912\n");

        $this->assertTrue((new EnvWriter)->dumpStatus($this->dir, '127.0.0.1:9912')['connected']);
    }

    public function test_dump_status_connected_when_server_key_is_absent_and_we_host_the_default(): void
    {
        // var-dumper defaults VAR_DUMPER_SERVER to 127.0.0.1:9912, so
        // format=server alone counts as connected on the default host.
        $this->writeEnv("VAR_DUMPER_FORMAT=server\n");

        $this->assertTrue((new EnvWriter)->dumpStatus($this->dir, '127.0.0.1:9912')['connected']);
        $this->assertFalse((new EnvWriter)->dumpStatus($this->dir, '127.0.0.1:9999')['connected']);
    }

    public function test_dump_status_not_connected_without_server_format(): void
    {
        $this->writeEnv("APP_NAME=Demo\n");

        $status = (new EnvWriter)->dumpStatus($this->dir, '127.0.0.1:9912');

        $this->assertTrue($status['exists']);
        $this->assertFalse($status['connected']);
    }

    public function test_connect_dumps_writes_both_keys_and_preserves_the_rest(): void
    {
        $this->writeEnv("APP_NAME=Demo\nVAR_DUMPER_FORMAT=html\n");

        $result = (new EnvWriter)->connectDumps($this->dir, '127.0.0.1:9912');
        $env = file_get_contents($this->dir.'/.env');

        $this->assertTrue($result['ok']);
        $this->assertEqualsCanonicalizing(['VAR_DUMPER_FORMAT', 'VAR_DUMPER_SERVER'], $result['changed']);
        $this->assertStringContainsString('VAR_DUMPER_FORMAT=server', $env);
        $this->assertStringContainsString('VAR_DUMPER_SERVER=127.0.0.1:9912', $env);
        $this->assertStringContainsString('APP_NAME=Demo', $env);
        $this->assertStringNotContainsString('VAR_DUMPER_FORMAT=html', $env);
    }

    public function test_connect_dumps_is_idempotent(): void
    {
        $this->writeEnv("VAR_DUMPER_FORMAT=server\nVAR_DUMPER_SERVER=127.0.0.1:9912\n");

        $result = (new EnvWriter)->connectDumps($this->dir, '127.0.0.1:9912');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['changed']);
    }
}
