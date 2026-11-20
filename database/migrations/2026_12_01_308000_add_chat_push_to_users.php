<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likha Chat (spec 308): ang mga device na naka-on ang alerts (web push) ng bawat tao, gaya ng users.nav_pins:
 * [{endpoint, p256dh, auth, added_at, ua}], hanggang 10. Walang bagong table. Additive; down() ay tinatanggal ito.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'chat_push')) {
            Schema::table('users', function (Blueprint $t) {
                $t->json('chat_push')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'chat_push')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('chat_push'));
        }
    }
};
