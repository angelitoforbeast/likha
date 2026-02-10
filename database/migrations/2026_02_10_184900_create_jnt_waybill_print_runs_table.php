<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jnt_waybill_print_run_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('run_id')
                ->constrained('jnt_waybill_print_runs')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('macro_output_id')->nullable()->index();
            $table->string('mailno', 64)->index();
            $table->unsignedInteger('seq')->default(0)->index();

            $table->string('status', 20)->default('pending');          // pending|ok|fail
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['run_id','mailno']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_waybill_print_run_items');
    }
};
