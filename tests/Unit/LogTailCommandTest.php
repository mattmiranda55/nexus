<?php

namespace Tests\Unit;

use App\Services\LogTailCommand;
use Tests\TestCase;

class LogTailCommandTest extends TestCase
{
    private function windows(): LogTailCommand
    {
        return new LogTailCommand('Windows');
    }

    public function test_unix_uses_plain_tail_regardless_of_strategy(): void
    {
        $cmd = (new LogTailCommand('Darwin'))->build('/app/storage/logs/laravel.log', 'powershell');

        $this->assertTrue($cmd['ok']);
        $this->assertSame('tail', $cmd['strategy']);
        $this->assertSame(
            ['tail', '-n', '200', '-F', '/app/storage/logs/laravel.log'],
            $cmd['argv'],
        );
    }

    public function test_linux_uses_plain_tail(): void
    {
        $cmd = (new LogTailCommand('Linux'))->build('/app/storage/logs/laravel.log');

        $this->assertSame('tail', $cmd['strategy']);
        $this->assertSame('tail', $cmd['argv'][0]);
    }

    public function test_wsl_converts_the_drive_letter_to_a_mount_path(): void
    {
        $cmd = $this->windows()->build('C:\\app\\storage\\logs\\laravel.log', 'wsl');

        $this->assertTrue($cmd['ok']);
        $this->assertSame('wsl', $cmd['strategy']);
        $this->assertSame([
            'wsl.exe', 'tail', '-n', '200', '-F', '/mnt/c/app/storage/logs/laravel.log',
        ], $cmd['argv']);
    }

    public function test_powershell_quotes_the_native_path_and_forces_utf8(): void
    {
        $cmd = $this->windows()->build('C:\\app\\storage\\logs\\laravel.log', 'powershell');

        $this->assertTrue($cmd['ok']);
        $this->assertSame('powershell', $cmd['strategy']);
        $this->assertSame('powershell', $cmd['argv'][0]);
        $this->assertContains('-NoProfile', $cmd['argv']);

        $script = end($cmd['argv']);
        $this->assertStringContainsString("-LiteralPath 'C:\\app\\storage\\logs\\laravel.log'", $script);
        $this->assertStringContainsString('-Tail 200 -Wait', $script);
        $this->assertStringContainsString('OutputEncoding', $script);
    }

    public function test_git_bash_builds_an_msys_path_when_bash_is_present(): void
    {
        $tail = $this->windows();

        if ($tail->findGitBash() === null) {
            $this->markTestSkipped('Git Bash is not installed on this machine.');
        }

        $cmd = $tail->build('C:\\app\\storage\\logs\\laravel.log', 'gitbash');

        $this->assertTrue($cmd['ok']);
        $this->assertSame('gitbash', $cmd['strategy']);
        $this->assertSame('-c', $cmd['argv'][1]);
        $this->assertSame(
            "tail -n 200 -F '/c/app/storage/logs/laravel.log'",
            $cmd['argv'][2],
        );
    }

    public function test_git_bash_fails_with_a_actionable_message_when_absent(): void
    {
        $tail = $this->windows();

        if ($tail->findGitBash() !== null) {
            $this->markTestSkipped('Git Bash is installed, so the missing-binary path cannot be exercised.');
        }

        $cmd = $tail->build('C:\\app\\storage\\logs\\laravel.log', 'gitbash');

        $this->assertFalse($cmd['ok']);
        $this->assertSame([], $cmd['argv']);
        $this->assertStringContainsString('Git Bash', (string) $cmd['error']);
    }

    public function test_path_conversion_lowercases_the_drive_and_flips_separators(): void
    {
        $tail = $this->windows();

        $this->assertSame('/c/app/x.log', $tail->toMsysPath('C:\\app\\x.log'));
        $this->assertSame('/mnt/d/app/x.log', $tail->toWslPath('D:\\app\\x.log'));
    }

    public function test_posix_paths_pass_through_conversion_untouched(): void
    {
        $tail = $this->windows();

        $this->assertSame('/app/x.log', $tail->toMsysPath('/app/x.log'));
        $this->assertSame('/app/x.log', $tail->toWslPath('/app/x.log'));
    }

    public function test_unknown_strategies_fall_back_to_the_default(): void
    {
        $tail = $this->windows();

        $this->assertSame('gitbash', $tail->normalize(null));
        $this->assertSame('gitbash', $tail->normalize(''));
        $this->assertSame('gitbash', $tail->normalize('bash; rm -rf /'));
        $this->assertSame('wsl', $tail->normalize('WSL'));
        $this->assertSame('powershell', $tail->normalize(' PowerShell '));
    }
}
