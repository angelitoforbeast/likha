<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 298: who made each account. `users.created_by` is the id of the CEO or Marketing - OIC who made it at
 * /accounts/create; NULL for every account made before that page (the old public sign-up, seeds, by hand).
 * staff_events is not the place: it is pruned after 90 days and holds no id of the account that was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $t) {
                $t->unsignedBigInteger('created_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('created_by');
            });
        }
    }
};
