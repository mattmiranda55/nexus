<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // A mail server the user pinned in Settings ("kind|url"); null means
            // automatic — whatever they already run, by priority.
            $table->string('mail_pin', 2100)->nullable();
            // Nexus's downloaded Mailpit: off | nexus (start with Nexus when
            // nothing else is running) | login (start at login).
            $table->string('mailpit_mode')->default('off');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['mail_pin', 'mailpit_mode']);
        });
    }
};
