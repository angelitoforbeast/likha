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
    Schema::table('macro_output', function (Blueprint $table) {
        $table->boolean('edited_full_name')->default(false);
        $table->boolean('edited_phone_number')->default(false);
        $table->boolean('edited_address')->default(false);
        $table->boolean('edited_province')->default(false);
        $table->boolean('edited_city')->default(false);
        $table->boolean('edited_barangay')->default(false);
    });
}

public function down(): void
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->dropColumn([
            'edited_full_name',
            'edited_phone_number',
            'edited_address',
            'edited_province',
            'edited_city',
            'edited_barangay',
        ]);
    });
}

};
