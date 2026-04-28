<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('ads_manager_reports', 'account_id')) {
                $table->string('account_id', 191)->nullable()->after('campaign_id');
                $table->index('account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ads_manager_reports', 'account_id')) {
                $table->dropIndex(['account_id']);
                $table->dropColumn('account_id');
            }
        });
    }
};
