<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('botcake_psid_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gsheet_name')->nullable(); // title ng sheet (auto fetched)
            $table->text('sheet_url');                 // full URL
            $table->string('sheet_range');             // e.g. "PSID!A:J"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_psid_settings');
    }
};
