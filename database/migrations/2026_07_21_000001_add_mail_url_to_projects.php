<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Optional per-project Mailpit API URL, for Docker port remaps where
            // the convention (SMTP 1025 → HTTP 8025) doesn't hold.
            $table->string('mail_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('mail_url');
        });
    }
};
