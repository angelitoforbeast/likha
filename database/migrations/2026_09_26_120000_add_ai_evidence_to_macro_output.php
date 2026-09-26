<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AI EVIDENCE` sa macro_output — sinusulatan ng AI checker (MacroChecker):
 * mga web-search query/sources, dahilan ng pinili (hal. landmark → barangay),
 * at resulta ng validation gate. HINDI ipinapakita sa UI (audit/debug lang).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('macro_output') && !Schema::hasColumn('macro_output', 'AI EVIDENCE')) {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->text('AI EVIDENCE')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('macro_output') && Schema::hasColumn('macro_output', 'AI EVIDENCE')) {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->dropColumn('AI EVIDENCE');
            });
        }
    }
};
