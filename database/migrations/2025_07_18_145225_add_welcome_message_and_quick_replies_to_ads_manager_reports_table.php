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
    Schema::table('ads_manager_reports', function (Blueprint $table) {
        $table->text('welcome_message')->nullable();
        $table->text('quick_reply_1')->nullable();
        $table->text('quick_reply_2')->nullable();
        $table->text('quick_reply_3')->nullable();
    });
}

public function down(): void
{
    Schema::table('ads_manager_reports', function (Blueprint $table) {
        $table->dropColumn([
            'welcome_message',
            'quick_reply_1',
            'quick_reply_2',
            'quick_reply_3',
        ]);
    });
}

};
