<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->string('fb_name')->nullable()->after('PAGE');
            $table->text('shop_details')->nullable()->after('fb_name');
            $table->text('extracted_details')->nullable()->after('shop_details');
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropColumn(['fb_name', 'shop_details', 'extracted_details']);
        });
    }
};
