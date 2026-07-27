<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
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
        $path = Dialog::new()
            ->title('Select a Laravel project')
            ->folders()
            ->open();

        // A folder picker returns a single path string, or null if cancelled.
        $path = is_array($path) ? ($path[0] ?? null) : $path;

        if (! $path) {
            return to_route('console');
        }

        if (! File::exists(rtrim($path, '/').'/artisan')) {
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
