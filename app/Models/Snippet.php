<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Snippet extends Model
{
    protected $fillable = ['project_id', 'name', 'code'];

    /**
     * Snippets visible in a project: its own plus globals (null project_id).
     * With no project active, only globals.
     */
    public function scopeVisibleTo(Builder $query, ?int $projectId): Builder
    {
        return $query->where(function (Builder $q) use ($projectId) {
            $q->whereNull('project_id');
            if ($projectId !== null) {
                $q->orWhere('project_id', $projectId);
            }
        });
    }
}
