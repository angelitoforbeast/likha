<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOfflineAdsTable extends Migration
{
    public function up()
    {
        Schema::create('offline_ads', function (Blueprint $table) {
            $table->id();
            // Store your “YYYY-MM-DD” exactly as a string
            $table->string('reporting_starts', 10)->nullable();

            $table->string('campaign_name')->nullable();
            $table->string('adset_name')->nullable();
            $table->decimal('amount_spent', 12, 2)->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('messages')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('ad_delivery')->nullable();

            // Keep IDs as strings so concatenation matches on the PHP side
            $table->string('campaign_id')->nullable();
            $table->string('ad_id')->nullable();

            $table->unsignedBigInteger('reach')->nullable();
            $table->string('date_created', 10)->nullable(); // or keep as date if you prefer
            $table->string('hook_rate')->nullable();
            $table->string('hold_rate')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('offline_ads');
    }
}
