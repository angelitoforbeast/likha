<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zk_raw_users', function (Blueprint $table) {
            $table->id();
            // Biometric ID from the device (string para flexible; some devices use numeric/string mix)
            $table->string('zk_user_id')->index();

            // Raw name as extracted from user.dat (kept for auditing)
            $table->string('name_raw')->nullable();

            // Cleaned name (optional: you can fill this during/after parsing)
            $table->string('name_clean')->nullable();

            // Upload batch tag (e.g., "upload_2025-10-14_120301_AB12cd")
            $table->string('upload_batch')->nullable()->index();

            $table->timestamps();

            // If you want to prevent duplicates per user across uploads, you can uncomment this:
            // $table->unique('zk_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zk_raw_users');
    }
};
