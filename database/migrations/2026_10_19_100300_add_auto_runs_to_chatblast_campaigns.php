<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang mga oras ng padala na tapos na ngayong araw (Manila): {"date":"2026-10-18","times":["09:00","10:00"]}. Bawat
     * oras ay tumatakbo nang isang beses sa sarili nito; ang bagong oras na lumipas na ay itinatala rito (hindi tatakbo)
     * nang hindi ginagalaw ang ibang oras na hindi pa tumatakbo. NULL = wala pa.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatblast_campaigns') || Schema::hasColumn('chatblast_campaigns', 'auto_runs')) return;

        Schema::table('chatblast_campaigns', function (Blueprint $table) {
            $table->json('auto_runs')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chatblast_campaigns') && Schema::hasColumn('chatblast_campaigns', 'auto_runs')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->dropColumn('auto_runs');
            });
        }
    }
};
