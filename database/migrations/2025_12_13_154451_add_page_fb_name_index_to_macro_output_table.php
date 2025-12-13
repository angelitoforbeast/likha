<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            // Composite index for PAGE + fb_name
            $table->index(['PAGE', 'fb_name'], 'macro_output_page_fb_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropIndex('macro_output_page_fb_name_idx');
        });
    }
};
