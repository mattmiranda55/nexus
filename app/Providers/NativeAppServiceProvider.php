<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Mail\MailCatchers;
use App\Services\MailpitManager;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open()
            ->title('Nexus')
            ->width(1024)
            ->height(700)
            ->minWidth(768)
            ->minHeight(576)
            ->rememberState()
            // This window carries NativePHP's preload bridge (window.Native).
            // A link that opened a new window or navigated it off-host would
            // hand that bridge to an external page; external links go through
            // LinkController to the OS browser instead.
            ->suppressNewWindows()
            ->preventLeaveDomain();

        $this->startMailpitWithNexus();
    }

    /**
     * Settings → Mail → "Start with Nexus": run the downloaded Mailpit for this
     * session, but only when no other mail server is running — whatever the
     * user already has always wins. It dies with the app (it's a ChildProcess).
     */
    private function startMailpitWithNexus(): void
    {
        try {
            if (Setting::current()->mailpit_mode === 'nexus') {
                app(MailpitManager::class)->startWithNexus(app(MailCatchers::class)->detect());
            }
        } catch (\Throwable) {
            // Never let mail setup keep the window from opening.
        }
    }

    /**
     * php.ini directives for the bundled PHP runtime. OPcache keeps the Laravel
     * kernel hot so repeat requests (and boots) are fast.
     *
     * JIT is deliberately absent. Tracing JIT pays off on tight numeric loops;
     * a request-scoped Laravel app spends its time on I/O and array shuffling,
     * so it bought us ~nothing while burning real CPU compiling traces on every
     * cold start — very noticeable on a thermally-limited laptop.
     */
    public function phpIni(): array
    {
        return [
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'memory_limit' => '512M',

            // Skipping the per-include stat() is only safe when the files can't
            // change underneath us. In a packaged build they can't. In dev they
            // very much can, and a frozen cache means edits to PHP silently do
            // nothing until the whole app is restarted. Revalidating on a 2s
            // floor keeps dev honest without a stat() storm per request —
            // which matters on Windows, where every stat is a Defender hit.
            ...(config('app.debug')
                ? ['opcache.validate_timestamps' => '1', 'opcache.revalidate_freq' => '2']
                : ['opcache.validate_timestamps' => '0']),
        ];
    }
}
