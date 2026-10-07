<?php

namespace App\Services;

use Closure;
use Symfony\Component\Process\Process;

/**
 * "Start Mailpit when I log in", per user and without admin rights:
 *
 *  - macOS:   a LaunchAgent in ~/Library/LaunchAgents, loaded with launchctl
 *  - Linux:   a systemd --user unit, enabled with systemctl --user
 *  - Windows: a value under HKCU\…\CurrentVersion\Run. (A real Windows
 *             service needs elevation and a service wrapper.) Mailpit is a
 *             console program, so it's launched through `conhost --headless`
 *             to keep a console window from appearing at every login.
 *
 * The login item runs the Mailpit downloaded in Settings, which lives in
 * Nexus's storage — a fixed path that survives app updates.
 */
class MailpitAutostart
{
    public const LABEL = 'com.nexus.mailpit';

    public const UNIT = 'nexus-mailpit.service';

    public const RUN_KEY = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run';

    public const RUN_VALUE = 'NexusMailpit';

    private string $os;

    private string $home;

    private Closure $run;

    /**
     * @param  ?Closure(list<string>): bool  $run  runs a command, true on exit 0 (tests swap it)
     */
    public function __construct(
        private MailpitManager $mailpit,
        ?string $home = null,
        ?string $os = null,
        ?Closure $run = null,
    ) {
        $this->os = $os ?? PHP_OS_FAMILY;
        $this->home = rtrim($home ?? self::userHome(), '/\\');
        $this->run = $run ?? function (array $command): bool {
            $process = new Process($command);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful();
        };
    }

    /**
     * The user's home directory. Not just $HOME: some launchers (Laravel's
     * `artisan serve` among them) pass the PHP server a trimmed environment.
     */
    private static function userHome(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: ($_SERVER['HOME'] ?? '');

        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? '';
        }

        return (string) $home;
    }

    public function supported(): bool
    {
        return in_array($this->os, ['Darwin', 'Linux', 'Windows'], true) && ($this->os === 'Windows' || $this->home !== '');
    }

    public function enabled(): bool
    {
        return match ($this->os) {
            'Darwin' => is_file($this->plistPath()),
            'Linux' => is_file($this->unitPath()),
            'Windows' => ($this->run)(['reg', 'query', self::RUN_KEY, '/v', self::RUN_VALUE]),
            default => false,
        };
    }

    /** @return array{ok: bool, error: ?string} */
    public function enable(): array
    {
        if (! $this->supported()) {
            return ['ok' => false, 'error' => 'Starting Mailpit at login isn\'t supported on this system.'];
        }

        $binary = $this->mailpit->resolveBinary();
        if ($binary === null) {
            return ['ok' => false, 'error' => 'Download Mailpit first.'];
        }

        $argv = $this->mailpit->arguments($binary);

        $ok = match ($this->os) {
            'Darwin' => $this->enableLaunchAgent($argv),
            'Linux' => $this->enableSystemdUnit($argv),
            'Windows' => ($this->run)(['reg', 'add', self::RUN_KEY, '/v', self::RUN_VALUE, '/t', 'REG_SZ', '/d', $this->windowsCommand($argv), '/f']),
        };

        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The system refused to add the login item.'];
    }

    /** @return array{ok: bool, error: ?string} */
    public function disable(): array
    {
        $ok = match ($this->os) {
            'Darwin' => $this->disableLaunchAgent(),
            'Linux' => $this->disableSystemdUnit(),
            'Windows' => ! $this->enabled() || ($this->run)(['reg', 'delete', self::RUN_KEY, '/v', self::RUN_VALUE, '/f']),
            default => true,
        };

        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The system refused to remove the login item.'];
    }

    // --- macOS ---------------------------------------------------------------

    public function plistPath(): string
    {
        return $this->home.'/Library/LaunchAgents/'.self::LABEL.'.plist';
    }

    /** @param  list<string>  $argv */
    public function plist(array $argv): string
    {
        $args = implode('', array_map(
            fn ($a) => "\n        <string>".htmlspecialchars($a, ENT_XML1 | ENT_QUOTES).'</string>',
            $argv,
        ));

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>Label</key>
            <string>{$this->label()}</string>
            <key>ProgramArguments</key>
            <array>{$args}
            </array>
            <key>RunAtLoad</key>
            <true/>
        </dict>
        </plist>

        XML;
    }

    private function label(): string
    {
        return self::LABEL;
    }

    private function enableLaunchAgent(array $argv): bool
    {
        $this->write($this->plistPath(), $this->plist($argv));
        $domain = 'gui/'.$this->uid();

        // Unload any previous version first; bootstrap fails on a loaded label.
        ($this->run)(['launchctl', 'bootout', $domain.'/'.self::LABEL]);

        return ($this->run)(['launchctl', 'bootstrap', $domain, $this->plistPath()]);
    }

    private function disableLaunchAgent(): bool
    {
        ($this->run)(['launchctl', 'bootout', 'gui/'.$this->uid().'/'.self::LABEL]);

        return ! is_file($this->plistPath()) || @unlink($this->plistPath());
    }

    private function uid(): int
    {
        return function_exists('posix_getuid') ? posix_getuid() : (int) getmyuid();
    }

    // --- Linux ---------------------------------------------------------------

    public function unitPath(): string
    {
        return $this->home.'/.config/systemd/user/'.self::UNIT;
    }

    /** @param  list<string>  $argv */
    public function unit(array $argv): string
    {
        // systemd splits ExecStart like a shell; quote each argument.
        $exec = implode(' ', array_map(fn ($a) => '"'.addcslashes($a, '"\\').'"', $argv));

        return <<<INI
        [Unit]
        Description=Mailpit (started by Nexus)

        [Service]
        ExecStart={$exec}
        Restart=on-failure

        [Install]
        WantedBy=default.target

        INI;
    }

    private function enableSystemdUnit(array $argv): bool
    {
        $this->write($this->unitPath(), $this->unit($argv));
        ($this->run)(['systemctl', '--user', 'daemon-reload']);

        return ($this->run)(['systemctl', '--user', 'enable', '--now', self::UNIT]);
    }

    private function disableSystemdUnit(): bool
    {
        ($this->run)(['systemctl', '--user', 'disable', '--now', self::UNIT]);
        $removed = ! is_file($this->unitPath()) || @unlink($this->unitPath());
        ($this->run)(['systemctl', '--user', 'daemon-reload']);

        return $removed;
    }

    // --- Windows -------------------------------------------------------------

    /**
     * The Run value: one command line. conhost --headless (Windows 10 1809+)
     * hosts the console program without a window.
     *
     * @param  list<string>  $argv
     */
    public function windowsCommand(array $argv): string
    {
        return implode(' ', array_map(
            fn ($a) => preg_match('/[\s"]/', $a) ? '"'.str_replace('"', '\\"', $a).'"' : $a,
            ['conhost.exe', '--headless', ...$argv],
        ));
    }

    // -------------------------------------------------------------------------

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }
}
