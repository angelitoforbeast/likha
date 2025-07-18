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
    Schema::create('ad_campaign_creatives', function (Blueprint $table) {
        $table->id();
        $table->string('campaign_id')->unique();
        $table->string('campaign_name')->nullable();
        $table->text('headline')->nullable();
        $table->text('body_ad_settings')->nullable();
        $table->text('welcome_message')->nullable();
        $table->text('quick_reply_1')->nullable();
        $table->text('quick_reply_2')->nullable();
        $table->text('quick_reply_3')->nullable();
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_creatives');
    }
};
