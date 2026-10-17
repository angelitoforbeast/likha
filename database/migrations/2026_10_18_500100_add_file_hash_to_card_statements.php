<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sha256 ng inupload na statement file (hindi ang file mismo), para ang parehong file na inulit sa loob ng
 * 10 minuto (hal. pagkatapos ng 504 habang natapos pa rin ang basa) ay magbukas ng dating statement, hindi doble.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('card_statements') && !Schema::hasColumn('card_statements', 'file_hash')) {
            Schema::table('card_statements', function (Blueprint $t) {
                $t->char('file_hash', 64)->nullable();
                $t->index(['file_hash', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('card_statements', 'file_hash')) {
            Schema::table('card_statements', function (Blueprint $t) {
                $t->dropIndex(['file_hash', 'created_at']);
                $t->dropColumn('file_hash');
            });
        }
    }
};
