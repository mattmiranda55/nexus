<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Run;
use App\Models\Setting;
use App\Services\TinkerExecutor;
use App\Services\TinkerJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\ChildProcess;

class TinkerController extends Controller
{
    /**
     * Start a tinker run.
     *
     * In the desktop app the run is handed to a `nexus:tinker-run` ChildProcess
     * and this answers 202 straight away: a run can take up to 60s, and the PHP
     * server handles one request at a time, so running it here would freeze
     * logs, mail and everything else until it finished. The client then waits
     * for the worker's "done" line (or the process exiting) and collects the
     * result from GET /tinker/{id}.
     *
     * Outside Electron (`composer dev`, tests) there is no ChildProcess, so the
     * run happens inline and the result comes back in this response.
     */
    public function run(Request $request, TinkerExecutor $executor, TinkerJobs $jobs): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
            'id' => 'nullable|uuid',
        ]);

        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['output' => 'Error: No project selected', 'envelope' => null], 422);
        }

        if (! config('nativephp-internal.running')) {
            return response()->json($executor->execute($project, $data['code']));
        }

        // The client picks the id so it can subscribe to the worker's events
        // before this request returns — a fast run can't finish unobserved.
        $id = strtolower($data['id'] ?? (string) str()->uuid());

        if ($jobs->exists($id)) {
            return response()->json(['message' => 'A run with this id already exists.'], 409);
        }

        $jobs->create($id, ['project_id' => $project->id, 'code' => $data['code']]);

        try {
            ChildProcess::artisan(['nexus:tinker-run', $id], TinkerJobs::alias($id));
        } catch (\Throwable) {
            // The desktop runtime didn't take it; a blocking run beats none.
            $jobs->forget($id);

            return response()->json($executor->execute($project, $data['code']));
        }

        return response()->json(['status' => 'running', 'id' => $id], 202);
    }

    /**
     * Collect a background run's result: 200 with the result (once), 202 while
     * it is still going, 404 for an unknown id.
     *
     * `exited=1` is the client reporting that the worker process has exited.
     * The worker writes its result before it exits, so a missing result at
     * that point means it died; the same goes for a run past STALE_AFTER.
     */
    public function result(Request $request, string $id, TinkerJobs $jobs): JsonResponse
    {
        if ($result = $jobs->result($id)) {
            $jobs->forget($id);

            return response()->json($result);
        }

        $queuedAt = $jobs->queuedAt($id);

        if ($queuedAt === null) {
            return response()->json(['message' => 'Unknown tinker run.'], 404);
        }

        if ($request->boolean('exited') || time() - $queuedAt > TinkerJobs::STALE_AFTER) {
            $jobs->forget($id);

            return response()->json(TinkerExecutor::failure('The tinker worker stopped without returning a result.'));
        }

        return response()->json(['status' => 'running'], 202);
    }

    /**
     * Stop a background run: kill the worker (NativePHP stops the whole
     * process tree, so the tinker child goes with it) and record it in
     * history as a failed run, so its code can still be restored.
     *
     * If the worker finished first, its real result is returned instead.
     */
    public function stop(string $id, TinkerJobs $jobs): JsonResponse
    {
        $job = $jobs->job($id);

        if ($job === null && $jobs->result($id) === null) {
            return response()->json(['message' => 'Unknown tinker run.'], 404);
        }

        try {
            ChildProcess::stop(TinkerJobs::alias($id));
        } catch (\Throwable) {
            // Already gone, or no desktop runtime — either way it isn't running.
        }

        // Read after the kill: a result written in the meantime wins.
        if ($result = $jobs->result($id)) {
            $jobs->forget($id);

            return response()->json($result);
        }

        $queuedAt = $jobs->queuedAt($id) ?? time();
        $jobs->forget($id);

        if ($job && Project::whereKey($job['project_id'] ?? null)->exists()) {
            Run::record((int) $job['project_id'], (string) ($job['code'] ?? ''), false, max(0, time() - $queuedAt) * 1000);
        }

        return response()->json(TinkerExecutor::failure('Run stopped.') + ['stopped' => true]);
    }
}
