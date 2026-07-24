<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\EnvWriter;
use Illuminate\Http\JsonResponse;
use Native\Desktop\Facades\ChildProcess;

/**
 * The dump receiver's lifecycle + project wiring. The listener itself is
 * `nexus:dump-server` running as a ChildProcess (same pattern as the log
 * tail); dumps reach the UI as MessageReceived events, never through here.
 */
class DumpController extends Controller
{
    private const ALIAS = 'dumps';

    /** One global receiver; var-dumper's conventional dump-server port. */
    public const HOST = '127.0.0.1:9912';

    /** Is the active project's .env routed to us? (UI decides what to offer.) */
    public function status(EnvWriter $env): JsonResponse
    {
        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        return response()->json([
            'host' => self::HOST,
            'project' => $project
                ? ['name' => $project->name] + $env->dumpStatus($project->path, self::HOST)
                : null,
        ]);
    }

    /**
     * (Re)start the listener. Global, not per-project — every connected app
     * lands in the same stream, and entries carry their source path.
     */
    public function start(): JsonResponse
    {
        ChildProcess::stop(self::ALIAS);

        ChildProcess::artisan(
            ['nexus:dump-server', '--host='.self::HOST],
            self::ALIAS,
            persistent: true, // resurrect if the listener ever dies
        );

        return response()->json(['status' => 'started', 'host' => self::HOST]);
    }

    public function stop(): JsonResponse
    {
        ChildProcess::stop(self::ALIAS);

        return response()->json(['status' => 'stopped']);
    }

    /** One-click "route this app's dump() here": writes the two .env lines. */
    public function connect(EnvWriter $env): JsonResponse
    {
        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        $result = $env->connectDumps($project->path, self::HOST);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['ok' => true, 'changed' => $result['changed']]);
    }
}
