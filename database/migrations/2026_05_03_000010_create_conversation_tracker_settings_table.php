<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tracker_settings', function (Blueprint $t) {
            $t->id();
            $t->string('sheet_url', 500);
            $t->string('sheet_id', 255)->index();
            $t->string('spreadsheet_title', 255)->nullable();
            $t->string('selected_sheet_name', 255)->nullable();
            $t->string('range', 255)->default('A2:J');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tracker_settings');
    }
};
