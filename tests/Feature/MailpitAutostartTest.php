<?php

namespace Tests\Feature;

use App\Services\MailpitAutostart;
use App\Services\MailpitManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Login items for each OS, with a recording command runner and a temp home
 * directory — nothing here touches the real system.
 */
class MailpitAutostartTest extends TestCase
{
    private string $home;

    private string $storage;

    /** @var list<list<string>> */
    private array $commands = [];

    private bool $succeed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/nexus home '.uniqid();
        $this->storage = sys_get_temp_dir().'/nexus-storage-'.uniqid();
        mkdir($this->home, 0755, true);
        mkdir($this->storage, 0755, true);
        file_put_contents($this->storage.'/mailpit-src', 'binary');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function autostart(string $os, ?string $binary = 'default'): MailpitAutostart
    {
        $binary = $binary === 'default' ? $this->storage.'/mailpit-src' : $binary;
        $storage = $this->storage;

        $mailpit = new class($binary, $storage) extends MailpitManager
        {
            public function __construct(private ?string $binary, private string $dir)
            {
                parent::__construct();
            }

            public function resolveBinary(): ?string
            {
                return $this->binary;
            }

            public function downloadDir(): string
            {
                return $this->dir;
            }
        };

        return new MailpitAutostart($mailpit, $this->home, $os, function (array $command) {
            $this->commands[] = $command;

            return $this->succeed;
        });
    }

    public function test_macos_installs_and_loads_a_launch_agent(): void
    {
        $autostart = $this->autostart('Darwin');

        $this->assertSame(['ok' => true, 'error' => null], $autostart->enable());

        $plist = file_get_contents($autostart->plistPath());
        $this->assertStringContainsString('<string>'.$this->storage.'/mailpit-src</string>', $plist);
        $this->assertStringContainsString('<string>--smtp</string>', $plist);
        $this->assertStringContainsString('<key>RunAtLoad</key>', $plist);
        $this->assertTrue($autostart->enabled());
        $this->assertSame('bootstrap', $this->commands[1][1]);
        $this->assertSame($autostart->plistPath(), $this->commands[1][3]);

        $this->assertTrue($autostart->disable()['ok']);
        $this->assertFileDoesNotExist($autostart->plistPath());
        $this->assertFalse($autostart->enabled());
    }

    public function test_linux_writes_and_enables_a_user_unit_with_quoted_arguments(): void
    {
        $autostart = $this->autostart('Linux');

        $this->assertTrue($autostart->enable()['ok']);

        $unit = file_get_contents($autostart->unitPath());
        $this->assertStringContainsString('ExecStart="'.$this->storage.'/mailpit-src" "--smtp"', $unit);
        $this->assertContains(['systemctl', '--user', 'enable', '--now', 'nexus-mailpit.service'], $this->commands);

        $autostart->disable();
        $this->assertFileDoesNotExist($autostart->unitPath());
    }

    public function test_windows_adds_a_hidden_run_entry_for_the_current_user(): void
    {
        $autostart = $this->autostart('Windows');

        $this->assertTrue($autostart->enable()['ok']);

        [$command] = $this->commands;
        $this->assertSame(['reg', 'add', MailpitAutostart::RUN_KEY, '/v', 'NexusMailpit', '/t', 'REG_SZ', '/d'], array_slice($command, 0, 8));
        $this->assertStringStartsWith('conhost.exe --headless ', $command[8]);
        $this->assertStringContainsString('--listen 127.0.0.1:8025', $command[8]);
    }

    public function test_windows_quotes_paths_with_spaces(): void
    {
        $this->assertSame(
            'conhost.exe --headless "C:\Users\Jo Smith\mailpit.exe" --smtp 127.0.0.1:1025',
            $this->autostart('Windows')->windowsCommand(['C:\Users\Jo Smith\mailpit.exe', '--smtp', '127.0.0.1:1025']),
        );
    }

    public function test_without_a_binary_it_asks_for_a_download_first(): void
    {
        $result = $this->autostart('Darwin', null)->enable();

        $this->assertFalse($result['ok']);
        $this->assertSame('Download Mailpit first.', $result['error']);
        $this->assertSame([], $this->commands);
    }

    public function test_a_refused_system_command_is_reported(): void
    {
        $this->succeed = false;

        $this->assertFalse($this->autostart('Windows')->enable()['ok']);
    }
}
