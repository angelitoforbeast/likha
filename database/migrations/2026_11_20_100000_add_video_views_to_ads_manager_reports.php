<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 244: 3-second plays, ThruPlays and 50% views per ad and day, filled by the Meta Ads sync. Null means "no data"
 * (an image ad, or a row from the upload), never 0. Nullable, no default and appended last (no after()), so MySQL 8
 * adds them with ALGORITHM=INSTANT: a metadata change that does not rebuild or copy the large live table.
 */
return new class extends Migration
{
    private const COLUMNS = ['video_plays_3s', 'video_thruplays', 'video_p50'];

    public function up(): void
    {
        $missing = array_values(array_filter(self::COLUMNS, fn ($c) => !Schema::hasColumn('ads_manager_reports', $c)));
        if (!$missing) return;
        Schema::table('ads_manager_reports', function (Blueprint $table) use ($missing) {
            foreach ($missing as $c) $table->unsignedInteger($c)->nullable();
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('ads_manager_reports', $c)));
        if (!$present) return;
        Schema::table('ads_manager_reports', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
