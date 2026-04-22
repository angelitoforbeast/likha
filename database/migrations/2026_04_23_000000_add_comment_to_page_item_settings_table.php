<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_item_settings', function (Blueprint $table) {
            $table->string('comment', 500)->nullable()->after('effective_date');
        });
    }

    public function down(): void
    {
        Schema::table('page_item_settings', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
