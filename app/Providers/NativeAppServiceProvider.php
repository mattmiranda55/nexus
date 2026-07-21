<?php

namespace App\Providers;

use Native\Desktop\Facades\Window;
use Native\Desktop\Contracts\ProvidesPhpIni;

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
            ->rememberState();
    }

    /**
     * php.ini directives for the bundled PHP runtime. OPcache + JIT keep the
     * Laravel kernel hot so repeat requests (and boots) are fast.
     */
    public function phpIni(): array
    {
        return [
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.jit' => 'tracing',
            'opcache.jit_buffer_size' => '64M',
            'opcache.validate_timestamps' => '0',
            'memory_limit' => '512M',
        ];
    }
}
