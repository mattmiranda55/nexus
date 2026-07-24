<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['name', 'path', 'mail_url'];

    /**
     * The conventional path to this project's Laravel log file.
     */
    public function logPath(): string
    {
        return rtrim($this->path, '/').'/storage/logs/laravel.log';
    }
}
