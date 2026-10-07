<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\LogTailCommand;
use Inertia\Inertia;
use Inertia\Response;

class ConsoleController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::current();

        return Inertia::render('Console', [
            'projects' => Project::orderBy('name')->get(['id', 'name', 'path']),
            'settings' => [
                'theme' => $settings->theme,
                'phpPath' => $settings->php_path,
                'editor' => $settings->editor,
                'notifyErrors' => (bool) $settings->notify_errors,
                'notifyMail' => (bool) ($settings->notify_mail ?? true),
                'logShell' => $settings->log_shell ?? LogTailCommand::DEFAULT_STRATEGY,
                'mailUrl' => $settings->mail_url,
                'mailPin' => $settings->mail_pin,
                'mailpitMode' => $settings->mailpit_mode ?? 'off',
            ],
            // Drives the Windows-only bits of the UI (log-shell picker, modifier
            // key glyphs). The renderer can't tell what OS it's on reliably.
            'platform' => PHP_OS_FAMILY,
            'activeProjectId' => $settings->active_project_id,
        ]);
    }
}
