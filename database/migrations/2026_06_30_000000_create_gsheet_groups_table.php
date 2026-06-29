<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsheet_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('likha_url')->nullable();   // source — reads TO ENCODER!L1
            $table->text('macro_url')->nullable();    // macro — reads LINKS!E2 (task count)
            $table->text('after_url')->nullable();    // after-macro — reads DATABASE!N1 (-1)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsheet_groups');
    }
};
