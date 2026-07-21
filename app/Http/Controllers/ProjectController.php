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
            return back();
        }

        if (! File::exists(rtrim($path, '/').'/artisan')) {
            return back()->with('error', 'That folder is not a Laravel project (no artisan file found).');
        }

        $project = Project::firstOrCreate(
            ['path' => $path],
            ['name' => basename($path)],
        );

        Setting::current()->update(['active_project_id' => $project->id]);

        return back();
    }

    public function activate(Project $project): RedirectResponse
    {
        Setting::current()->update(['active_project_id' => $project->id]);

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        $settings = Setting::current();

        if ((int) $settings->active_project_id === $project->id) {
            $settings->update(['active_project_id' => null]);
        }

        $project->delete();

        return back();
    }
}
