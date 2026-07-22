<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Editor scheme for click-to-source (phpstorm | vscode | ...).
            $table->string('editor')->default('phpstorm');
            // Fire an OS notification when a streamed log hits error/critical.
            $table->boolean('notify_errors')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['editor', 'notify_errors']);
        });
    }
};
