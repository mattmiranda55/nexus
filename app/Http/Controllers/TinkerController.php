<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\TinkerRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TinkerController extends Controller
{
    public function run(Request $request, TinkerRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['output' => 'Error: No project selected', 'envelope' => null], 422);
        }

        $result = $runner->runStructured($project->path, $data['code']);

        return response()->json([
            'envelope' => $result['envelope'],
            'raw' => $result['raw'],
            // Back-compat alias: the raw/CLI-parity view is the old `output`.
            'output' => $result['raw'],
        ]);
    }
}
