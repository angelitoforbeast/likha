<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 294: who each page would block. One counter row per user, route pattern, decision and Manila day
 * (never one row per request). Separate from staff_events, which feeds the staff status and the office.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_records')) return;

        Schema::create('access_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('role', 100)->default('');
            $t->string('path_pattern', 255);           // the route's URI pattern, e.g. /finance/supply/{supplier}
            $t->string('decision', 20);                // would_block | unmapped
            $t->date('day');                           // Manila date
            $t->unsignedInteger('hits')->default(1);
            $t->timestamp('last_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'path_pattern', 'decision', 'day'], 'access_records_user_path_decision_day_unique');
            $t->index(['day', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_records');
    }
};
