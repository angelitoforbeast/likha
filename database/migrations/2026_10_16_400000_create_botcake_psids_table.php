<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Kasaysayan ng bawat PSID row na naproseso mula sa Botcake sheet. Hindi binubura: status lang ang nagbabago,
        // kaya buo pa rin ang record kahit binura na ang row sa sheet.
        Schema::create('botcake_psids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->string('page');
            $table->string('fb_name');
            $table->string('psid', 64);
            $table->string('sheet_date')->nullable(); // column D ng sheet, eksaktong text
            $table->string('status', 20); // waiting|matched|expired|conflict
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->unsignedInteger('matched_rows')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['page', 'fb_name']);
            $table->index('status');
            $table->index('psid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_psids');
    }
};
