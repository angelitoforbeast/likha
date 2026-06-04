<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_hold_snapshots — araw-araw na snapshot ng HOLD (units per base item)
 * mula sa /jnt/hold logic (macro_output rows na may waybill pero wala pa sa
 * from_jnts = pending/held). Current-state ang hold, kaya kino-capture daily
 * para may HISTORY (hindi current-value-na-kinopya) sa /owner/private.
 *
 * snapshot_date = ang araw na kinakatawan ng snapshot (karaniwan KAHAPON —
 * kinukuha ng 6AM cron). hold_units = base-item units na naka-hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('item_hold_snapshots')) {
            Schema::create('item_hold_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('item_key')->index();    // normalized base item (lower, qty-stripped)
                $table->string('item_name');            // display base item
                $table->date('snapshot_date')->index();
                $table->unsignedInteger('hold_units')->default(0);
                $table->timestamp('captured_at')->nullable();
                $table->timestamps();
                $table->unique(['item_key', 'snapshot_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_hold_snapshots');
    }
};
