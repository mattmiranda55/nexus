<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LogTailCommand;
use App\Services\MailpitMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function update(Request $request, MailpitMode $mailpitMode): RedirectResponse
    {
        $data = $request->validate([
            'theme' => 'required|in:dark,light',
            'phpPath' => 'nullable|string|max:1024',
            'editor' => 'required|in:phpstorm,vscode,vscodium,cursor,sublime,textmate',
            'notifyErrors' => 'boolean',
            'notifyMail' => 'boolean',
            'logShell' => ['nullable', Rule::in(LogTailCommand::STRATEGIES)],
            // http(s) only: MailController fetches this URL server-side.
            'mailUrl' => 'nullable|url:http,https|max:2048',
            // A detected mail server's id ("kind|url"), or null for automatic.
            'mailPin' => 'nullable|string|max:2100',
            'mailpitMode' => ['nullable', Rule::in(MailpitMode::MODES)],
        ]);

        Setting::current()->update([
            'theme' => $data['theme'],
            'php_path' => ($data['phpPath'] ?? null) ?: null,
            'editor' => $data['editor'],
            'notify_errors' => $data['notifyErrors'] ?? false,
            'notify_mail' => $data['notifyMail'] ?? true,
            'log_shell' => $data['logShell'] ?? LogTailCommand::DEFAULT_STRATEGY,
            'mail_url' => rtrim((string) ($data['mailUrl'] ?? ''), '/') ?: null,
            'mail_pin' => ($data['mailPin'] ?? null) ?: null,
        ]);

        // Starting, stopping or registering Mailpit has side effects that can
        // fail (no download yet, the OS refusing a login item), so it reports
        // back as a field error rather than being saved blindly.
        $mode = $data['mailpitMode'] ?? Setting::current()->mailpit_mode ?? 'off';
        if ($mode !== Setting::current()->mailpit_mode) {
            $result = $mailpitMode->apply($mode);
            if (! $result['ok']) {
                return back()->withErrors(['mailpitMode' => $result['error']]);
            }
        }

        return to_route('console');
    }
}
