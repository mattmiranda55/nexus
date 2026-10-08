<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\EnvWriter;
use App\Services\Mail\MailCatcher;
use App\Services\Mail\MailCatchers;
use App\Services\MailpitAutostart;
use App\Services\MailpitManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Notification;

/**
 * Email hub. The inbox reads from whichever mail catcher is already running on
 * this machine (smtp4dev, Mailpit, MailHog — see MailCatchers) and shows the
 * mail in Nexus's own UI. Nexus's own Mailpit is the fallback: downloaded from
 * Settings, then started with Nexus or at login as the user chose there.
 *
 * The inbox is global, not per project; the per-project part is whether each
 * app's .env sends its mail to the chosen catcher.
 */
class MailController extends Controller
{
    /** The ChildProcess relaying the catcher's live events (scripts/mail-watch.mjs). */
    public const WATCH_ALIAS = 'mail-watch';

    /** Notification references for new mail: this prefix + the message id. */
    public const NOTIFICATION_PREFIX = 'nexus-mail:';

    public function __construct(
        private MailCatchers $catchers,
        private MailpitManager $mailpit,
        private MailpitAutostart $autostart,
        private EnvWriter $env,
    ) {}

    /**
     * Detect what's running and settle which one the inbox reads (see
     * MailCatchers::pick). With nothing running and "start with Nexus"
     * chosen, start Nexus's Mailpit; the UI polls while it binds.
     */
    public function status(): JsonResponse
    {
        $found = $this->catchers->detect();
        $active = $this->catchers->pick($found);
        $current = $this->catchers->active();

        if (($active ? MailCatchers::id($active) : null) !== ($current ? MailCatchers::id($current) : null)) {
            $this->catchers->choose($active);
        }

        $starting = $this->mailpit->startWithNexus($found);

        return response()->json($this->state($found, $active) + ['mailpitStarting' => $starting]);
    }

    /**
     * Relay the active catcher's live events to the UI through a ChildProcess
     * (the app window can't hold these sockets itself — Mailpit rejects a
     * cross-origin websocket). `live: false` means poll instead.
     */
    public function watch(): JsonResponse
    {
        $catcher = $this->catchers->active();
        $live = $catcher?->live();

        try {
            ChildProcess::stop(self::WATCH_ALIAS);
        } catch (\Throwable) {
            //
        }

        if (! $live || $live['type'] === 'poll' || ! config('nativephp-internal.running')) {
            return response()->json(['live' => false]);
        }

        try {
            ChildProcess::node([
                base_path('scripts'.DIRECTORY_SEPARATOR.'mail-watch.mjs'),
                $live['type'],
                $live['url'],
            ], self::WATCH_ALIAS);
        } catch (\Throwable) {
            return response()->json(['live' => false]);
        }

        return response()->json(['live' => true]);
    }

    public function messages(): JsonResponse
    {
        return $this->withCatcher(fn (MailCatcher $c) => ['messages' => $c->messages()]);
    }

    public function message(string $id): JsonResponse
    {
        $id = $this->safeId($id);
        if ($id === '') {
            return response()->json(['error' => 'Unknown message'], 404);
        }

        return $this->withCatcher(fn (MailCatcher $c) => $c->message($id));
    }

    public function raw(string $id): JsonResponse
    {
        $id = $this->safeId($id);
        if ($id === '') {
            return response()->json(['error' => 'Unknown message'], 404);
        }

        return $this->withCatcher(fn (MailCatcher $c) => ['raw' => $c->raw($id)]);
    }

    /** Clear the whole inbox. */
    public function destroy(): JsonResponse
    {
        return $this->withCatcher(function (MailCatcher $c) {
            $c->deleteAll();

            return ['ok' => true];
        });
    }

    /**
     * Desktop notification for newly arrived mail. The renderer decides when
     * (new messages while the window isn't focused); this owns the native
     * hand-off. The reference carries the message id, so a click can open it
     * (see AppServiceProvider and Console.vue).
     */
    public function notify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => 'required|integer|min:1|max:10000',
            'id' => 'required|string|max:200',
            'subject' => 'nullable|string|max:300',
            'from' => 'nullable|string|max:300',
        ]);

        if (! (Setting::current()->notify_mail ?? true)) {
            return response()->json(['status' => 'disabled']);
        }

        $subject = trim((string) ($data['subject'] ?? '')) ?: '(no subject)';
        [$title, $body] = $data['count'] > 1
            ? ["{$data['count']} new emails", "Latest: {$subject}"]
            : ['New email'.(($data['from'] ?? '') !== '' ? " from {$data['from']}" : ''), $subject];

        try {
            Notification::title($title)
                ->message($body)
                ->reference(self::NOTIFICATION_PREFIX.$this->safeId($data['id']))
                ->show();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'sent']);
    }

    /** One-click "connect this app": point a project's .env at the active catcher. */
    public function connect(Project $project): JsonResponse
    {
        $catcher = $this->catchers->active();
        if (! $catcher) {
            return response()->json(['ok' => false, 'error' => 'No mail server selected'], 422);
        }

        $result = $this->env->connectMailpit($project->path, $catcher->smtpHost(), $catcher->smtpPort());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    // --- The bundled Mailpit fallback ----------------------------------------

    /** Start Nexus's Mailpit once, for this session. The UI then polls status. */
    public function startMailpit(): JsonResponse
    {
        return response()->json(['state' => $this->mailpit->start()]);
    }

    /** Download Mailpit into storage; the UI waits for the process to exit. */
    public function downloadMailpit(): JsonResponse
    {
        $started = $this->mailpit->download();

        return response()->json(
            ['started' => $started, 'alias' => MailpitManager::DOWNLOAD_ALIAS],
            $started ? 200 : 422,
        );
    }

    /** Delete the downloaded Mailpit (and its login item). */
    public function removeMailpit(): JsonResponse
    {
        if ($this->autostart->supported() && $this->autostart->enabled()) {
            $this->autostart->disable();
        }
        Setting::current()->update(['mailpit_mode' => 'off']);

        if (! $this->mailpit->remove()) {
            return response()->json(['ok' => false, 'error' => 'Mailpit is still running. Quit it and try again.'], 409);
        }

        return response()->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    /** @param  list<MailCatcher>  $found */
    private function state(array $found, ?MailCatcher $active): array
    {
        return [
            'sources' => array_map(fn ($c) => MailCatchers::describe($c), $found),
            'active' => $active ? MailCatchers::describe($active) : null,
            'projects' => $active ? $this->wiring($active) : [],
            'pin' => Setting::current()->mail_pin,
            'mailpit' => [
                'installed' => $this->mailpit->resolveBinary() !== null,
                'version' => $this->mailpit->version(),
                'mode' => Setting::current()->mailpit_mode,
                'loginSupported' => $this->autostart->supported(),
            ],
        ];
    }

    /** Which projects' .env files send their mail to $catcher. */
    private function wiring(MailCatcher $catcher): array
    {
        return Project::orderBy('name')->get()->map(function (Project $project) use ($catcher) {
            $mail = $this->env->mailStatus($project->path, $catcher->smtpPort());
            $from = strtolower(trim((string) ($mail['values']['MAIL_FROM_ADDRESS'] ?? '')));

            return [
                'id' => $project->id,
                'name' => $project->name,
                'hasEnv' => $mail['exists'],
                'connected' => $mail['connected'],
                // Lets the inbox tag mail with the project that sent it. A
                // value built from other variables (${…}) can't be matched.
                'mailFrom' => $from !== '' && ! str_contains($from, '${') ? $from : null,
            ];
        })->all();
    }

    /** Run against the active catcher, turning any failure into a 502. */
    private function withCatcher(callable $callback): JsonResponse
    {
        $catcher = $this->catchers->active();
        if (! $catcher) {
            return response()->json(['error' => 'No mail server selected.'], 409);
        }

        try {
            return response()->json($callback($catcher));
        } catch (\Throwable) {
            return response()->json(['error' => "{$catcher->label()} isn't reachable at {$catcher->url()}."], 502);
        }
    }

    /** Message IDs are opaque tokens (Mailpit ids, smtp4dev GUIDs, MailHog ids@host). */
    private function safeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9\-_.@]/', '', $id);
    }
}
