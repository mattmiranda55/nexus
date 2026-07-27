<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\LogTailCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Native\Desktop\Facades\ChildProcess;

class LogController extends Controller
{
    private const ALIAS = 'tail';

    /**
     * Tail the active project's laravel.log as an Electron-side child process,
     * backfilling the last 200 lines and then following. New output is pushed
     * to the UI via ChildProcess MessageReceived events, so it never occupies
     * the single-threaded PHP server.
     *
     * The actual command is platform-dependent (see LogTailCommand): plain
     * `tail -F` on Unix, and on Windows whichever shell the user picked in
     * Settings, since Windows has no tail of its own.
     */
    public function start(LogTailCommand $tail): JsonResponse
    {
        $settings = Setting::current();
        $activeId = $settings->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['status' => 'error', 'error' => 'No project selected'], 422);
        }

        $path = $project->logPath();

        // Restart cleanly if a tail is already running.
        ChildProcess::stop(self::ALIAS);

        // `tail -F` on a nonexistent path stays silent and waits for the file to
        // appear, so the UI would sit on "Connecting…" forever. Answer up front
        // instead: no file, no tail, and the path we looked for.
        if (! File::exists($path)) {
            return response()->json([
                'status' => 'missing',
                'error' => 'No log file at '.$path,
                'path' => $path,
            ], 422);
        }

        $command = $tail->build($path, $settings->log_shell);

        // Nothing to run — e.g. Git Bash selected on Windows but not installed.
        // That's a configuration problem, so say so rather than failing blank.
        if (! $command['ok']) {
            return response()->json([
                'status' => 'error',
                'error' => $command['error'],
                'path' => $path,
                'strategy' => $command['strategy'],
            ], 422);
        }

        try {
            ChildProcess::start($command['argv'], self::ALIAS);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => 'Could not start the log tail: '.$e->getMessage(),
                'path' => $path,
                'strategy' => $command['strategy'],
            ], 500);
        }

        return response()->json([
            'status' => 'started',
            'path' => $path,
            'strategy' => $command['strategy'],
        ]);
    }

    public function stop(): JsonResponse
    {
        ChildProcess::stop(self::ALIAS);

        return response()->json(['status' => 'stopped']);
    }
}
