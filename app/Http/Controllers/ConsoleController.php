<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use Inertia\Inertia;
use Inertia\Response;

class ConsoleController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::current();

        return Inertia::render('Console', [
            'projects' => Project::orderBy('name')->get(['id', 'name', 'path']),
            'settings' => [
                'theme' => $settings->theme,
                'phpPath' => $settings->php_path,
            ],
            'activeProjectId' => $settings->active_project_id,
        ]);
    }
}
