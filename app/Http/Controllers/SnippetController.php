<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Snippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tier D-lite: a per-project (or global) library of named tinker snippets.
 * Plain JSON endpoints — the palette and toolbar fetch these on demand.
 */
class SnippetController extends Controller
{
    /** Snippets visible in the active project (globals + its own). */
    public function index(): JsonResponse
    {
        $projectId = Setting::current()->active_project_id;

        $snippets = Snippet::visibleTo($projectId)
            ->orderBy('name')
            ->get(['id', 'project_id', 'name', 'code']);

        return response()->json(['snippets' => $snippets]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string',
            // true = save for every project, not just the active one.
            'global' => 'sometimes|boolean',
        ]);

        $projectId = $request->boolean('global') ? null : Setting::current()->active_project_id;

        // Same name in the same scope = overwrite, so "Save snippet" is
        // idempotent instead of piling up near-duplicates.
        $snippet = Snippet::updateOrCreate(
            ['project_id' => $projectId, 'name' => $data['name']],
            ['code' => $data['code']],
        );

        return response()->json(['snippet' => $snippet]);
    }

    public function destroy(Snippet $snippet): JsonResponse
    {
        $snippet->delete();

        return response()->json(['ok' => true]);
    }
}
