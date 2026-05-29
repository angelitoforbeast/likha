<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * /conversation/cancel-detector feature — sheet sources configuration.
 *
 * Mirror ng conversation_tracker_settings pattern. User adds a Google Sheet
 * URL + tab + range, and the import job pulls rows from each configured
 * source.
 *
 * Default range A2:N — needs col N for the "DONE" idempotency marker
 * (data columns A..L; col M reserved; col N = marker).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cancel_detector_settings')) return;

        Schema::create('cancel_detector_settings', function (Blueprint $t) {
            $t->id();
            $t->string('sheet_url', 500);
            $t->string('sheet_id', 255)->index();
            $t->string('spreadsheet_title', 255)->nullable();
            $t->string('selected_sheet_name', 255)->nullable();
            $t->string('range', 255)->default('A2:N');
            $t->boolean('is_archived')->default(false)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_detector_settings');
    }
};
