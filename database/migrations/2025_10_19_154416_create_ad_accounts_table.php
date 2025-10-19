<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            // Only what you asked for:
            $table->string('ad_account_id')->unique(); // e.g. 421938277125959
            $table->string('name');                     // e.g. Page / Business name
            $table->timestamps();

            $table->index('ad_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_accounts');
    }
};
