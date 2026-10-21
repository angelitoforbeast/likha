<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('checklist_analysis_logs') || Schema::hasColumn('checklist_analysis_logs', 'is_override')) {
            return;
        }

        // Approval row na itinakda ng CEO o OIC sa kamay (may dahilan sa analysis_result), hindi ng AI
        Schema::table('checklist_analysis_logs', function (Blueprint $table) {
            $table->boolean('is_override')->default(false)->after('verdict');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('checklist_analysis_logs', 'is_override')) {
            return;
        }

        Schema::table('checklist_analysis_logs', function (Blueprint $table) {
            $table->dropColumn('is_override');
        });
    }
};
