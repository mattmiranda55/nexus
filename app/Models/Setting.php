<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['theme', 'php_path', 'active_project_id'];

    /**
     * The app keeps a single settings row. Fetch it (creating defaults once).
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'theme' => 'dark',
        ]);
    }
}
