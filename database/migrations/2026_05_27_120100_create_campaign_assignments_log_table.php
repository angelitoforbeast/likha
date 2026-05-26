<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `campaign_assignments_log` — audit history ng all assignment changes.
 *
 * Every time an assignment is created/updated/cleared, a row gets inserted
 * here with the old + new employee IDs + who made the change.
 *
 * Read by: campaigns_history.blade.php (history modal on demand).
 * Written by: CampaignAssignmentController::save() after every successful upsert.
 *
 * Append-only — never updated or deleted (yung old logs preserved for audit).
 * Can purge old entries via cron after N months if table grows too big.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_assignments_log')) return;

        Schema::create('campaign_assignments_log', function (Blueprint $t) {
            $t->id();
            $t->string('campaign_id', 191);
            $t->unsignedBigInteger('old_employee_id')->nullable();
            $t->unsignedBigInteger('new_employee_id')->nullable();
            $t->unsignedBigInteger('changed_by_user_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['campaign_id', 'created_at']);
            $t->index('changed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_assignments_log');
    }
};
