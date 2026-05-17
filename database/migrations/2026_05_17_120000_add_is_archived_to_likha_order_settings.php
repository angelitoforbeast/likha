<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('likha_order_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('likha_order_settings', 'is_archived')) {
                // Archived rows stay visible sa settings UI but are skipped by
                // the import job. Allows users to retire a sheet without losing
                // its config — unarchive anytime to bring it back.
                $t->boolean('is_archived')->default(false)->after('range')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('likha_order_settings', function (Blueprint $t) {
            if (Schema::hasColumn('likha_order_settings', 'is_archived')) {
                $t->dropColumn('is_archived');
            }
        });
    }
};
