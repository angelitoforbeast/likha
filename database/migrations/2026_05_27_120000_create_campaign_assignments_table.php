<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `campaign_assignments` — current ownership ng each FB campaign.
 *
 * Isang row per campaign_id (UNIQUE constraint enforces this). Stores
 * who currently "owns" / "runs" the campaign — tagged manually via
 * /ads_manager/campaigns/history dropdown.
 *
 * Read by: campaigns_history.blade.php (bulk fetch on page load).
 * Written by: CEO / Marketing-OIC via dropdown change.
 *
 * Non-destructive: bagong table, walang touched sa existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_assignments')) return;

        Schema::create('campaign_assignments', function (Blueprint $t) {
            $t->id();
            $t->string('campaign_id', 191)->unique();
            // FK to employee_profiles.id — nullable allows "unassigned"
            $t->unsignedBigInteger('assigned_employee_id')->nullable();
            // Who last clicked save (audit trail)
            $t->unsignedBigInteger('assigned_by_user_id')->nullable();
            // Optional note attached to assignment
            $t->string('note', 255)->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamps();

            $t->index('assigned_employee_id');
            $t->index('assigned_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_assignments');
    }
};
