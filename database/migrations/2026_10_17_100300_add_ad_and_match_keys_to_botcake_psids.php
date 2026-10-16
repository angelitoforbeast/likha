<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('botcake_psids', function (Blueprint $table) {
            // Ang ad na pinanggalingan ng customer ayon sa Botcake (ad_id) at ang buong listahan (ad_ids).
            $table->string('ad_id', 64)->nullable()->index();
            $table->json('ad_ids')->nullable();

            // Normalised na pangalan at unang phone (09XXXXXXXXX), para sa on-demand na ambiguous / no match ng
            // psid-coverage page sa SQL (naka-index; ang matching mismo ay sa PHP at gumagamit ng lahat ng phone).
            $table->string('name_key')->nullable();
            $table->string('phone_key', 20)->nullable();

            $table->index(['source', 'page', 'status', 'name_key', 'psid']);
            $table->index(['source', 'page', 'status', 'phone_key', 'psid']);
        });
    }

    public function down(): void
    {
        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->dropIndex(['source', 'page', 'status', 'name_key', 'psid']);
            $table->dropIndex(['source', 'page', 'status', 'phone_key', 'psid']);
            $table->dropIndex(['ad_id']);
            $table->dropColumn(['ad_id', 'ad_ids', 'name_key', 'phone_key']);
        });
    }
};
