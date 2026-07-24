<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    /** Keep this many runs per project; older ones are pruned on insert. */
    public const KEEP = 100;

    protected $fillable = ['project_id', 'code', 'ok', 'duration_ms'];

    protected $casts = ['ok' => 'boolean'];

    /**
     * Record a run and prune the project's history down to the newest KEEP
     * rows in the same request — cheap enough that no scheduler is needed.
     */
    public static function record(int $projectId, string $code, bool $ok, int $durationMs): self
    {
        $run = static::create([
            'project_id' => $projectId,
            'code' => $code,
            'ok' => $ok,
            'duration_ms' => $durationMs,
        ]);

        $cutoff = static::where('project_id', $projectId)
            ->orderByDesc('id')
            ->skip(self::KEEP)
            ->value('id');

        if ($cutoff !== null) {
            static::where('project_id', $projectId)->where('id', '<=', $cutoff)->delete();
        }

        return $run;
    }
}
