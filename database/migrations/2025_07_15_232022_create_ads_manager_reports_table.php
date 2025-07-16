<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ads_manager_reports', function (Blueprint $table) {
            $table->id();

            $table->date('day')->nullable();
            $table->string('page_name'); // NOT NULL
            $table->string('campaign_name')->nullable();
            $table->string('ad_set_id')->nullable();
            $table->string('ad_set_name')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('campaign_delivery')->nullable();
            $table->string('ad_set_delivery')->nullable();
            $table->bigInteger('reach')->nullable();
            $table->bigInteger('impressions')->nullable();
            $table->decimal('ad_set_budget', 12, 2)->nullable();
            $table->string('ad_set_budget_type')->nullable();
            $table->string('attribution_setting')->nullable();
            $table->string('result_type')->nullable();
            $table->bigInteger('results')->nullable();
            $table->decimal('amount_spent_php', 12, 2)->nullable();
            $table->decimal('cost_per_result', 12, 2)->nullable();
            $table->dateTime('starts')->nullable();
            $table->dateTime('ends')->nullable();
            $table->bigInteger('messaging_conversations_started')->nullable();
            $table->bigInteger('purchases')->nullable();
            $table->dateTime('reporting_starts')->nullable();
            $table->dateTime('reporting_ends')->nullable();

            $table->timestamps(); // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_manager_reports');
    }
};
