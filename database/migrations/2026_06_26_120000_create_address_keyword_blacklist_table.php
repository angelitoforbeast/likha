<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * address_keyword_blacklist — keywords na hinahanap sa ADDRESS (Line 1) tuwing
 * Validate / Validate 1. Partial + case-insensitive match → invalid/TO FIX.
 * Host-scoped (likha vs incepxion).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('address_keyword_blacklist')) {
            Schema::create('address_keyword_blacklist', function (Blueprint $table) {
                $table->id();
                $table->string('keyword');
                $table->string('reason')->nullable();
                $table->string('host_scope', 20)->default('likha')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('address_keyword_blacklist');
    }
};
