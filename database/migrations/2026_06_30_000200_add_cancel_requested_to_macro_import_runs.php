<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('macro_import_runs', function (Blueprint $table) {
            // Website sets this true; the job reads it per-sheet and stops gracefully.
            $table->boolean('cancel_requested')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('macro_import_runs', function (Blueprint $table) {
            $table->dropColumn('cancel_requested');
        });
    }
};
