<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Run;
use App\Models\Setting;
use App\Services\LogDeltaReader;
use App\Services\TinkerRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TinkerController extends Controller
{
    public function run(Request $request, TinkerRunner $runner, LogDeltaReader $logs): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['output' => 'Error: No project selected', 'envelope' => null], 422);
        }

        // A4 run↔log correlation: snapshot the log size, then read exactly what
        // this run appended to it — fusing the REPL and the log viewer.
        $logPath = $project->logPath();
        $before = $logs->size($logPath);

        $started = hrtime(true);
        $result = $runner->runStructured($project->path, $data['code']);

        // Run history: an envelope means the code executed to completion and
        // produced a structured result; its absence means tinker bailed early
        // (parse error, exception, missing binary).
        Run::record(
            $project->id,
            $data['code'],
            $result['envelope'] !== null,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );

        return response()->json([
            'envelope' => $result['envelope'],
            'raw' => $result['raw'],
            // Back-compat alias: the raw/CLI-parity view is the old `output`.
            'output' => $result['raw'],
            'loggedDuringRun' => $logs->read($logPath, $before),
        ]);
    }
}
