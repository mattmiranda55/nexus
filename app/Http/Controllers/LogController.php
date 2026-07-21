<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Native\Desktop\Facades\ChildProcess;

class LogController extends Controller
{
    private const ALIAS = 'tail';

    /**
     * Tail the active project's laravel.log as an Electron-side child process.
     * `tail -n 200 -F` backfills the last 200 lines, then follows (and survives
     * rotation). New output is pushed to the UI via ChildProcess MessageReceived
     * events, so it never occupies the single-threaded PHP server.
     */
    public function start(): JsonResponse
    {
        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        // Restart cleanly if a tail is already running.
        ChildProcess::stop(self::ALIAS);

        ChildProcess::start(
            ['tail', '-n', '200', '-F', $project->logPath()],
            self::ALIAS,
        );

        return response()->json([
            'status' => 'started',
            'path' => $project->logPath(),
        ]);
    }

    public function stop(): JsonResponse
    {
        ChildProcess::stop(self::ALIAS);

        return response()->json(['status' => 'stopped']);
    }
}
