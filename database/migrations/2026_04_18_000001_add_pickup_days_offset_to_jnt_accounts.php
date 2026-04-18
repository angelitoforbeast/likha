<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jnt_accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('pickup_days_offset')->default(0)->after('use_item_sender_mapping');
        });
    }

    public function down(): void
    {
        Schema::table('jnt_accounts', function (Blueprint $table) {
            $table->dropColumn('pickup_days_offset');
        });
    }
};
