<?php

namespace App\Http\Controllers;

use App\Models\Run;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Run history for the active project. Rows are recorded by TinkerController
 * on every run and pruned to Run::KEEP per project, so this is read/clear only.
 */
class HistoryController extends Controller
{
    public function index(): JsonResponse
    {
        $projectId = Setting::current()->active_project_id;

        if (! $projectId) {
            return response()->json(['runs' => []]);
        }

        $runs = Run::where('project_id', $projectId)
            ->orderByDesc('id')
            ->get(['id', 'code', 'ok', 'duration_ms', 'created_at']);

        return response()->json(['runs' => $runs]);
    }

    public function destroy(): JsonResponse
    {
        $projectId = Setting::current()->active_project_id;

        if ($projectId) {
            Run::where('project_id', $projectId)->delete();
        }

        return response()->json(['ok' => true]);
    }
}
