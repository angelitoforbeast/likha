<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Settings kada page bilang rows (Page | Provider | Key | Value): bagong uri ng setting = bagong row, hindi bagong column.
        // Isang ACTIVE row lang kada page + provider + key; ang pinalitan ay naiiwan bilang history (active = false, replaced_at).
        if (Schema::hasTable('page_settings')) return;

        Schema::create('page_settings', function (Blueprint $table) {
            $table->id();
            $table->string('page_name'); // kapareho ng macro_output.PAGE (trim, case-insensitive ang paghahambing)
            $table->string('provider', 50); // hal. botcake
            $table->string('key', 100); // hal. page_id, token, flow.shipping
            $table->text('value')->nullable(); // naka-encrypt kapag is_secret
            $table->boolean('is_secret')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('replaced_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // null = system / command
            $table->timestamps();

            $table->index(['page_name', 'provider', 'key', 'active']);
            $table->index(['provider', 'key', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_settings');
    }
};
