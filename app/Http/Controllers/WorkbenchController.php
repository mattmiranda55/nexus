<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Services\ArtisanRunner;
use App\Services\MigrateStatusParser;
use App\Services\TinkerRunner;
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

    /** C7 — database overview: db:show --json (connection + table list). */
    public function dbTables(): JsonResponse
    {
        return $this->withProject(function (Project $project) {
            $result = $this->artisan->run($project->path, ['db:show', '--json', '--counts']);

            if (! $result['ok']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json(['database' => $result['json']]);
        });
    }

    /** C7 — one table's schema: db:table {name} --json (columns + indexes). */
    public function dbTable(Request $request): JsonResponse
    {
        $name = $this->tableName($request);

        return $this->withProject(function (Project $project) use ($name) {
            $result = $this->artisan->run($project->path, ['db:table', $name, '--json']);

            if (! $result['ok']) {
                return response()->json(['error' => $result['error']], 422);
            }

            return response()->json(['table' => $result['json']]);
        });
    }

    /**
     * C7 — browse a table's rows. The query is generated here (never from free
     * text) and runs through the same structured tinker pipeline as the REPL,
     * so the response is a Tier B envelope the table view already renders.
     */
    public function dbRows(Request $request, TinkerRunner $tinker): JsonResponse
    {
        $name = $this->tableName($request);
        $offset = max(0, (int) $request->input('offset', 0));

        return $this->withProject(function (Project $project) use ($tinker, $name, $offset) {
            $code = sprintf(
                "DB::table('%s')->offset(%d)->limit(50)->get();",
                $name,
                $offset,
            );

            $result = $tinker->runStructured($project->path, $code);

            if ($result['envelope'] === null) {
                return response()->json(['error' => trim($result['raw']) ?: 'Query failed'], 422);
            }

            return response()->json(['envelope' => $result['envelope'], 'offset' => $offset]);
        });
    }

    /** Table names are identifiers, never SQL: strip everything else. */
    private function tableName(Request $request): string
    {
        $data = $request->validate(['table' => 'required|string|max:128']);

        return preg_replace('/[^A-Za-z0-9_]/', '', $data['table']);
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
