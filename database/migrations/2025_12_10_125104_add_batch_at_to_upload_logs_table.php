<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('upload_logs', 'batch_at')) {
                $table->timestamp('batch_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('upload_logs', function (Blueprint $table) {
            if (Schema::hasColumn('upload_logs', 'batch_at')) {
                $table->dropColumn('batch_at');
            }
        });
    }
};
