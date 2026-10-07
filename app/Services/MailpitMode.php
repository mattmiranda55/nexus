<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Mail\MailCatchers;

/**
 * Applies the Settings choice for Nexus's own Mailpit:
 *
 *  - off:   never started by Nexus
 *  - nexus: started by Nexus whenever no other mail server is running
 *  - login: registered to start at login (MailpitAutostart), so it runs even
 *           while Nexus is closed
 */
class MailpitMode
{
    public const MODES = ['off', 'nexus', 'login'];

    public function __construct(
        private MailpitManager $mailpit,
        private MailpitAutostart $autostart,
        private MailCatchers $catchers,
    ) {}

    /** @return array{ok: bool, error: ?string} */
    public function apply(string $mode): array
    {
        $settings = Setting::current();
        $previous = $settings->mailpit_mode ?? 'off';

        if ($mode !== 'off' && $this->mailpit->resolveBinary() === null) {
            return ['ok' => false, 'error' => 'Download Mailpit first.'];
        }

        if ($mode === 'login' && ! $this->autostart->supported()) {
            return ['ok' => false, 'error' => 'Starting Mailpit at login isn\'t supported on this system.'];
        }

        // Undo the old mode first, so two copies never fight over the ports.
        if ($previous === 'login' && $mode !== 'login' && $this->autostart->enabled()) {
            $result = $this->autostart->disable();
            if (! $result['ok']) {
                return $result;
            }
        }
        if ($mode !== 'nexus') {
            $this->mailpit->stop();
        }

        if ($mode === 'login') {
            $result = $this->autostart->enable();
            if (! $result['ok']) {
                return $result;
            }
            // launchctl/systemctl start it right away; a Windows Run entry
            // only applies at the next login, so cover this session.
            if (! $this->mailpit->detect()) {
                $this->mailpit->start();
            }
        }

        $settings->update(['mailpit_mode' => $mode]);

        if ($mode === 'nexus') {
            $this->mailpit->startWithNexus($this->catchers->detect());
        }

        return ['ok' => true, 'error' => null];
    }
}
