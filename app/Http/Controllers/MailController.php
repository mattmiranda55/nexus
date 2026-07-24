<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\EnvWriter;
use App\Services\MailpitManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Email hub: manages the Mailpit lifecycle and proxies its HTTP API, scoped to
 * the active project. The renderer connects to Mailpit's websocket directly for
 * live push; everything else goes through here so it stays CORS-free and can
 * honour a per-project API-URL override.
 */
class MailController extends Controller
{
    public function __construct(
        private MailpitManager $mailpit,
        private EnvWriter $env,
    ) {}

    /** Managed lifecycle + how the active project's .env is wired. */
    public function status(): JsonResponse
    {
        $state = $this->mailpit->status();
        $project = $this->activeProject();

        return response()->json([
            ...$state,
            'apiUrl' => $this->baseUrl($project),
            'mail' => $project
                ? $this->env->mailStatus($project->path, $this->mailpit->smtpPort())
                : null,
        ]);
    }

    /** Reuse a detected instance or launch the bundled binary. */
    public function start(): JsonResponse
    {
        $state = $this->mailpit->ensureRunning();
        $project = $this->activeProject();

        return response()->json([...$state, 'apiUrl' => $this->baseUrl($project)]);
    }

    public function messages(): JsonResponse
    {
        return $this->proxyJson('get', '/api/v1/messages?limit=200');
    }

    public function message(string $id): JsonResponse
    {
        $id = $this->safeId($id);

        return $this->proxyJson('get', "/api/v1/message/{$id}");
    }

    public function raw(string $id): JsonResponse
    {
        $id = $this->safeId($id);
        $base = $this->baseUrl($this->activeProject());

        try {
            $response = Http::timeout(5)->get("{$base}/api/v1/message/{$id}/raw");
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->unreachable()], 502);
        }

        return response()->json(['raw' => $response->body()]);
    }

    /** Clear the whole inbox. */
    public function destroy(): JsonResponse
    {
        return $this->proxyJson('delete', '/api/v1/messages');
    }

    /** One-click "connect this app": wire the active project's .env to Mailpit. */
    public function connect(): JsonResponse
    {
        $project = $this->activeProject();
        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        $result = $this->env->connectMailpit(
            $project->path,
            parse_url($this->baseUrl($project), PHP_URL_HOST) ?: '127.0.0.1',
            $this->mailpit->smtpPort(),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** Set (or clear) the per-project Mailpit API URL override. */
    public function config(Request $request): JsonResponse
    {
        $data = $request->validate(['mailUrl' => 'nullable|url']);
        $project = $this->activeProject();
        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        $project->update(['mail_url' => $data['mailUrl'] ?: null]);

        return response()->json(['ok' => true, 'apiUrl' => $this->baseUrl($project)]);
    }

    private function proxyJson(string $method, string $path): JsonResponse
    {
        $base = $this->baseUrl($this->activeProject());

        try {
            $response = Http::timeout(5)->{$method}("{$base}{$path}");
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->unreachable()], 502);
        }

        if (! $response->successful()) {
            return response()->json(['error' => $this->unreachable()], 502);
        }

        return response()->json($response->json() ?? []);
    }

    private function baseUrl(?Project $project): string
    {
        return $project?->mail_url ?: $this->mailpit->apiUrl();
    }

    private function activeProject(): ?Project
    {
        $id = Setting::current()->active_project_id;

        return $id ? Project::find($id) : null;
    }

    /** Mailpit message IDs are opaque tokens; keep them URL/path safe. */
    private function safeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9\-_]/', '', $id);
    }

    private function unreachable(): string
    {
        return 'Mailpit is not reachable. Start it from the Mail tab.';
    }
}
