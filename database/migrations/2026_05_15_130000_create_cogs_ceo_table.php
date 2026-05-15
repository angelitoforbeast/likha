<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CEO-only cogs table — mirrors structure of `cogs` but managed sa CEO level.
 *
 * Rationale: Marketing/MOIC see cogs.unit_cost; CEO sees cogs_ceo.unit_cost.
 * Profit calcs are role-dependent — CEO's view uses cogs_ceo, others use cogs.
 *
 * Auto-seed: on first migration, ALL existing cogs rows are copied to cogs_ceo
 * so the CEO has a starting baseline matching Marketing's values. Going forward,
 * when Marketing adds a new cogs entry, the auto-mirror logic sa controller
 * checks if (item_name, date) already exists sa cogs_ceo — kung wala, sync it;
 * kung meron, leave CEO's value untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cogs_ceo', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('item_name');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->json('history_logs')->nullable();
            $table->timestamps();

            $table->unique(['item_name', 'date'], 'cogs_ceo_item_date_unique');
            $table->index(['date', 'item_name'], 'cogs_ceo_date_item_idx');
        });

        // ── One-time seed: mirror existing cogs → cogs_ceo ─────────────────
        // Done sa raw SQL para fast at single transaction. Skips rows where
        // unit_cost is null since those have no value to mirror.
        if (Schema::hasTable('cogs')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('
                    INSERT INTO cogs_ceo (date, item_name, unit_cost, history_logs, created_at, updated_at)
                    SELECT date, item_name, unit_cost, history_logs, NOW(), NOW()
                    FROM cogs
                    ON CONFLICT (item_name, date) DO NOTHING
                ');
            } else {
                // MySQL
                DB::statement('
                    INSERT IGNORE INTO cogs_ceo (date, item_name, unit_cost, history_logs, created_at, updated_at)
                    SELECT date, item_name, unit_cost, history_logs, NOW(), NOW()
                    FROM cogs
                ');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cogs_ceo');
    }
};
