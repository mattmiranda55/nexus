<?php

/*
 * Nexus's own knobs. These live in config rather than being read with env() at
 * the call site because the packaged build runs `php artisan optimize`, and
 * once the config is cached env() returns null everywhere outside config/.
 */
return [

    // Explicit PHP binary for running a project's tinker. The Settings value
    // wins over this; both lose to a project-local Herd shim.
    'php_path' => env('NEXUS_PHP_PATH'),

    // Git for Windows' bash, for the default Windows log-tail strategy.
    'git_bash_path' => env('NEXUS_GIT_BASH_PATH'),

    // smtp4dev's web/API address (its SMTP port is read from the API).
    'smtp4dev' => [
        'url' => env('NEXUS_SMTP4DEV_URL', 'http://127.0.0.1:5000'),
    ],

    'mailpit' => [
        // Explicit Mailpit binary; otherwise the one downloaded in Settings.
        'path' => env('NEXUS_MAILPIT_PATH'),
        'host' => env('NEXUS_MAILPIT_HOST', '127.0.0.1'),
        'smtp_port' => (int) env('NEXUS_MAILPIT_SMTP_PORT', 1025),
        'http_port' => (int) env('NEXUS_MAILPIT_HTTP_PORT', 8025),
    ],

    // External pages the UI may hand to the OS browser. A fixed allowlist, so
    // the renderer can never ask the desktop shell to open an arbitrary URL.
    'links' => [
        'mailpit' => 'https://mailpit.axllent.org',
    ],

];
