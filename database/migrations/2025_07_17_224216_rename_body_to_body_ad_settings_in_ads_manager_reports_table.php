<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            $table->renameColumn('body', 'body_ad_settings');
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            $table->renameColumn('body_ad_settings', 'body');
        });
    }
};
