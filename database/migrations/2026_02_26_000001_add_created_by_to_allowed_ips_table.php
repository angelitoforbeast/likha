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
        Schema::table('allowed_ips', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('label')->index();
            // optional FK: commented out to avoid migration issues if users table differs
            // $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('allowed_ips', function (Blueprint $table) {
            // if foreign key used, dropForeign first
            // $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
