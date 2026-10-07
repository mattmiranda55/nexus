<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['theme', 'php_path', 'active_project_id', 'editor', 'notify_errors', 'log_shell', 'mail_url', 'mail_source', 'mail_pin', 'mailpit_mode', 'notify_mail'];

    protected $casts = ['notify_errors' => 'boolean', 'notify_mail' => 'boolean', 'mail_source' => 'array'];

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
