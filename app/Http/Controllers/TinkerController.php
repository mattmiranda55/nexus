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
            return response()->json(['output' => 'Error: No project selected'], 422);
        }

        return response()->json([
            'output' => $runner->run($project->path, $data['code']),
        ]);
    }
}
