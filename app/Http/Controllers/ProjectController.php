<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Native\Desktop\Dialog;

class ProjectController extends Controller
{
    /**
     * Open a native folder picker, validate the folder is a Laravel project,
     * then register and activate it. Mirrors the old Go AddProject + SelectDirectory.
     */
    public function store(): RedirectResponse
    {
        try {
            $path = Dialog::new()
                ->title('Select a Laravel project')
                ->folders()
                ->open();
        } catch (\Throwable) {
            return to_route('console')->with('error', 'The folder picker is only available in the desktop app.');
        }

        // A folder picker returns a single path string, or null if cancelled.
        $path = is_array($path) ? ($path[0] ?? null) : $path;

        if (! is_string($path) || $path === '') {
            return to_route('console');
        }

        // One spelling per folder, or "/app" and "/app/" register twice. A bare
        // root ("/", "C:\\") keeps its separator.
        $path = rtrim($path, '/\\') ?: $path;
        if (preg_match('/^[A-Za-z]:$/', $path)) {
            $path .= DIRECTORY_SEPARATOR;
        }

        if (! File::isDirectory($path) || ! File::isFile(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'artisan')) {
            return to_route('console')->with('error', 'That folder is not a Laravel project (no artisan file found).');
        }

        $project = Project::firstOrCreate(
            ['path' => $path],
            ['name' => basename($path)],
        );

        Setting::current()->update(['active_project_id' => $project->id]);

        return to_route('console');
    }

    /**
     * Redirect to the console route explicitly rather than `back()`: the app's
     * JSON endpoints share the `web` group, so the session's previous URL can
     * point at one of them — and a redirect there returns plain JSON, which the
     * Inertia client discards, leaving the sidebar looking dead.
     */
    public function activate(Project $project): RedirectResponse
    {
        Setting::current()->update(['active_project_id' => $project->id]);

        return to_route('console');
    }

    /**
     * Save the project's tinker editor contents. The editor debounces this, so
     * it's one small write after typing pauses, not one per keystroke.
     */
    public function scratch(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['code' => 'present|nullable|string|max:1000000']);

        $project->update(['scratch' => $data['code']]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Project $project): RedirectResponse
    {
        $settings = Setting::current();

        if ((int) $settings->active_project_id === $project->id) {
            $settings->update(['active_project_id' => null]);
        }

        $project->delete();

        return to_route('console');
    }
}
