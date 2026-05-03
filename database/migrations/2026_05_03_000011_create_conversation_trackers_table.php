<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_trackers', function (Blueprint $t) {
            $t->id();
            $t->dateTime('subscription_date')->nullable()->index();
            $t->string('subscription_date_raw', 100)->nullable();
            $t->dateTime('upload_date')->nullable()->index();
            $t->string('upload_date_raw', 50)->nullable();
            $t->string('page_name', 255)->nullable()->index();
            $t->string('name', 255)->nullable()->index();
            $t->string('phone_number', 50)->nullable()->index();
            $t->text('all_cx_details')->nullable();
            $t->mediumText('response_tracker')->nullable();
            $t->unsignedBigInteger('imported_run_id')->nullable()->index();
            $t->timestamps();
            // Composite for upsert lookup: (page_name, name) is the primary
            // identifier; phone is the tiebreaker when multiple match.
            $t->index(['page_name', 'name'], 'ct_page_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_trackers');
    }
};
