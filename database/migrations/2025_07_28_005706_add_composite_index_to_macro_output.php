<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->index(['TIMESTAMP', 'PAGE', 'fb_name'], 'timestamp_page_fbname_index');
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropIndex('timestamp_page_fbname_index');
        });
    }
};
