<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_sender_mappings', function (Blueprint $table) {
            $table->string('item_name')->nullable()->after('PAGE');

            // Drop the old unique on SENDER_NAME only — too restrictive,
            // multiple page+item combos should be allowed the same sender name.
            $table->dropUnique('uniq_sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('page_sender_mappings', function (Blueprint $table) {
            $table->dropColumn('item_name');
            $table->unique('SENDER_NAME', 'uniq_sender_name');
        });
    }
};
