<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\ArtisanRunner;
use App\Services\MigrateStatusParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Laravel workbench: read-only introspection panels (routes, models,
 * migrations) plus guarded write actions (migrate/rollback). Each panel is a
 * thin wrapper over an artisan command scoped to the active project; the
 * frontend renders the results through the Tier B table view.
 */
class WorkbenchController extends Controller
{
    public function __construct(private ArtisanRunner $artisan) {}

    /** C2 — route:list --json. */
    public function routes(): JsonResponse
    {
        return $this->withProject(function (Project $project) {
            $result = $this->artisan->run($project->path, ['route:list', '--json']);

            if (! $result['ok']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json(['routes' => $result['json'] ?? []]);
        });
    }

    /** C1 — discover model classes by globbing app/Models. */
    public function models(): JsonResponse
    {
        return $this->withProject(function (Project $project) {
            $dir = rtrim($project->path, '/').'/app/Models';
            $names = collect(glob($dir.'/*.php') ?: [])
                ->map(fn ($file) => basename($file, '.php'))
                ->sort()
                ->values();

            return response()->json(['models' => $names]);
        });
    }

    /** C1 — model:show {Model} --json (name qualified by artisan itself). */
    public function model(Request $request): JsonResponse
    {
        $data = $request->validate(['model' => 'required|string']);
        // Only a bare class name — artisan qualifies it to App\Models\*.
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $data['model']);

        return $this->withProject(function (Project $project) use ($name) {
            $result = $this->artisan->run($project->path, ['model:show', $name, '--json']);

            if (! $result['ok']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json(['model' => $result['json'], 'raw' => $result['output']]);
        });
    }

    /** C5 — migrate:status (parsed from text; no --json exists). */
    public function migrations(MigrateStatusParser $parser): JsonResponse
    {
        return $this->withProject(function (Project $project) use ($parser) {
            $result = $this->artisan->run($project->path, ['migrate:status']);

            if (! $result['ok']) {
                return response()->json(['error' => $result['error']], 422);
            }

            $rows = $parser->parse($result['output']);

            return response()->json([
                'migrations' => $rows,
                'pending' => collect($rows)->where('ran', false)->count(),
            ]);
        });
    }

    /** C5 — run pending migrations (destructive; frontend confirms first). */
    public function migrate(): JsonResponse
    {
        return $this->runWrite(['migrate', '--force']);
    }

    /** C5 — roll back the last batch (destructive; frontend confirms first). */
    public function rollback(): JsonResponse
    {
        return $this->runWrite(['migrate:rollback', '--force']);
    }

    private function runWrite(array $args): JsonResponse
    {
        return $this->withProject(function (Project $project) use ($args) {
            $result = $this->artisan->run($project->path, $args, 120);

            return response()->json([
                'ok' => $result['ok'],
                'output' => $result['ok'] ? $result['output'] : $result['error'],
            ], $result['ok'] ? 200 : 422);
        });
    }

    /** Resolve the active project or 422 with a consistent shape. */
    private function withProject(callable $callback): JsonResponse
    {
        $activeId = Setting::current()->active_project_id;
        $project = $activeId ? Project::find($activeId) : null;

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        return $callback($project);
    }
}
