<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ang mga customer mula sa Botcake API ay nasa botcake_psids din, bilang lookup (source = api, status = available).
        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->string('source', 10)->default('sheet'); // sheet|api
            $table->json('phone_numbers')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('last_interactive_at')->nullable();
            $table->string('botcake_customer_id', 64)->nullable();

            $table->index(['source', 'page', 'psid']);
        });

        // Bawat Botcake API sync ay isang run din: kaninong page, ilang batch, ilang bago / na-update, at ang matching.
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->string('source', 10)->default('sheet'); // sheet|api
            $table->string('page_name')->nullable();
            $table->unsignedInteger('batches_read')->default(0);
            $table->unsignedInteger('customers_new')->default(0);
            $table->unsignedInteger('customers_updated')->default(0);
            $table->unsignedInteger('seconds')->default(0);
            $table->unsignedInteger('matched_by_name')->default(0);
            $table->unsignedInteger('matched_by_phone')->default(0);
            $table->unsignedInteger('ambiguous')->default(0);
            $table->unsignedInteger('no_match')->default(0);

            $table->index(['source', 'page_name']);
        });
    }

    public function down(): void
    {
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->dropIndex(['source', 'page_name']);
            $table->dropColumn(['source', 'page_name', 'batches_read', 'customers_new', 'customers_updated', 'seconds',
                'matched_by_name', 'matched_by_phone', 'ambiguous', 'no_match']);
        });

        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->dropIndex(['source', 'page', 'psid']);
            $table->dropColumn(['source', 'phone_numbers', 'subscribed_at', 'last_interactive_at', 'botcake_customer_id']);
        });
    }
};
