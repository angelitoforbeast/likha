<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * "Bawat oras" ng sequence: {"start":"09:00","end":"21:00","every":1} (bawat N oras, kasama ang dulo kapag tumapat),
     * katabi ng send_times. NULL = wala. Hindi binabago ang oras ng mga sequence na live.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatblast_campaigns') || Schema::hasColumn('chatblast_campaigns', 'send_range')) return;

        Schema::table('chatblast_campaigns', function (Blueprint $table) {
            $table->json('send_range')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chatblast_campaigns') && Schema::hasColumn('chatblast_campaigns', 'send_range')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->dropColumn('send_range');
            });
        }
    }
};
