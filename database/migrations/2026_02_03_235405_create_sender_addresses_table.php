<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sender_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('jnt_sender_phone', 50)->nullable();
            $table->string('jnt_sender_prov', 60)->nullable();
            $table->string('jnt_sender_city', 100)->nullable();
            $table->string('jnt_sender_area', 50)->nullable();
            $table->string('jnt_sender_address', 300)->nullable();
            $table->timestamps();

            // optional kung 1 record per phone:
            // $table->unique('jnt_sender_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_addresses');
    }
};
