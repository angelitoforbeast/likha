<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `password_plain` column sa `users` table.
 *
 * SECURITY WARNING: stores plaintext password alongside the hashed `password`
 * column. Used by /owner/users CEO oversight view, gated server-side.
 *
 * Existing users na walang password set via /owner/users will have NULL here
 * until next reset — display ng "—" sa UI. Walang silent backfill ng plaintext
 * (impossible since bcrypt is one-way).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'password_plain')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('password_plain', 255)->nullable()->after('password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'password_plain')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('password_plain');
            });
        }
    }
};
