<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->boolean('edited_cod')->default(false)->after('COD'); 
            $table->boolean('edited_item_name')->default(false)->after('ITEM_NAME');
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropColumn(['edited_cod', 'edited_item_name']);
        });
    }
};

