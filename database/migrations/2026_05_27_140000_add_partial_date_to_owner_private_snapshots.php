<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `partial_date` (nullable DATE) to owner_private_snapshots so the
 * snapshot list can identify which captures included a partial_date 1D
 * overlay. Existing snapshots get NULL — they were saved before the field
 * existed; their payload may or may not contain partial_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('owner_private_snapshots')) return;
        if (Schema::hasColumn('owner_private_snapshots', 'partial_date')) return;

        Schema::table('owner_private_snapshots', function (Blueprint $t) {
            $t->date('partial_date')->nullable()->after('end_date');
            $t->index('partial_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('owner_private_snapshots')) return;
        if (!Schema::hasColumn('owner_private_snapshots', 'partial_date')) return;

        Schema::table('owner_private_snapshots', function (Blueprint $t) {
            $t->dropIndex(['partial_date']);
            $t->dropColumn('partial_date');
        });
    }
};
