<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['name', 'path', 'scratch'];

    /**
     * The conventional path to this project's Laravel log file, in the host
     * OS's own separator style — this string is shown to the user and handed to
     * the log tailer, so `C:\app/storage/logs/laravel.log` won't do.
     */
    public function logPath(): string
    {
        $base = rtrim($this->path, '/\\');
        $parts = ['storage', 'logs', 'laravel.log'];

        return $base.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }

    public function legacyLogPath(): string
    {
        $base = rtrim($this->path, '/\\');
        $parts = ['app', 'storage', 'logs', 'laravel.log'];

        return $base.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }
}
