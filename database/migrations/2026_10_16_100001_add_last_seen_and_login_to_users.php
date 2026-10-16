<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Huling kita at huling login ng bawat staff (para sa staff feed ng owner).
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->dateTime('last_seen_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'last_seen_path')) {
                $table->string('last_seen_path', 255)->nullable(); // path lang, walang query string
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->dateTime('last_login_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'last_seen_path', 'last_login_at']);
        });
    }
};
