<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('upload_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('error_rows');
            }
        });
    }

    public function down(): void
    {
        Schema::table('upload_logs', function (Blueprint $table) {
            if (Schema::hasColumn('upload_logs', 'error_message')) {
                $table->dropColumn('error_message');
            }
        });
    }
};
