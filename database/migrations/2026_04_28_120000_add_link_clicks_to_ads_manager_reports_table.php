<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('ads_manager_reports', 'link_clicks')) {
                $table->unsignedInteger('link_clicks')->nullable()->after('impressions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ads_manager_reports', 'link_clicks')) {
                $table->dropColumn('link_clicks');
            }
        });
    }
};
