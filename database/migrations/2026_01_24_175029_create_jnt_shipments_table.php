<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jnt_shipments', function (Blueprint $table) {
            $table->id();

            // Where this shipment came from (use what you want)
            $table->unsignedBigInteger('macro_output_id')->nullable()->index();

            // J&T identifiers
            $table->string('txlogisticid', 40)->nullable()->unique();
            $table->string('mailno', 40)->nullable()->index();

            // Latest known status
            $table->string('status', 30)->default('NEW')->index(); // NEW|CREATED|PENDING|CANCELED|FAILED|TRACKED|...

            // Request/Response logs (for debugging + audit)
            $table->json('request_payload')->nullable();     // business payload you built
            $table->json('request_protocol')->nullable();    // lastRequest (url, headers redacted, form redacted)
            $table->json('response_payload')->nullable();    // response JSON from J&T

            $table->string('last_reason', 50)->nullable();    // e.g. S02 / S17 etc
            $table->text('last_error')->nullable();          // exception message

            // Optional: quick fields for UI
            $table->string('receiver_name', 120)->nullable();
            $table->string('receiver_phone', 40)->nullable();
            $table->string('receiver_prov', 80)->nullable();
            $table->string('receiver_city', 80)->nullable();
            $table->string('receiver_area', 80)->nullable();

            $table->timestamps();

            // Optional FK if you want (if macro_output exists and is stable)
            // $table->foreign('macro_output_id')->references('id')->on('macro_output')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_shipments');
    }
};
