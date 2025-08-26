<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_campaign_creatives', function (Blueprint $table) {
            // 0 = No, 1 = Yes
            $table->boolean('feedback')->default(0)->after('ad_link');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaign_creatives', function (Blueprint $table) {
            $table->dropColumn('feedback');
        });
    }
};
