<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Mailpit inbox is shared by every project (several apps usually send to
 * the same Mailpit), so its API URL override moves from a per-project column
 * to the single settings row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('mail_url')->nullable();
        });

        // Keep an override someone already set: the active project's wins,
        // since that's the one the inbox was showing.
        $settings = DB::table('settings')->first();
        $url = DB::table('projects')
            ->whereNotNull('mail_url')
            ->orderByRaw('id = ? desc', [$settings?->active_project_id ?? 0])
            ->value('mail_url');

        if ($settings && $url) {
            DB::table('settings')->where('id', $settings->id)->update(['mail_url' => $url]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('mail_url');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('mail_url')->nullable();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('mail_url');
        });
    }
};
