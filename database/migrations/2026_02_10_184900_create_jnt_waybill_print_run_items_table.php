<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jnt_waybill_print_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->date('date')->nullable()->index();                 // for mode=all
            $table->string('mode', 20)->default('selected');           // selected|all
            $table->string('filter_by', 20)->nullable();               // page|item
            $table->string('filter_value')->nullable();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('ok_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);

            $table->string('status', 20)->default('queued');           // queued|building|running|finished|failed|cancelled
            $table->text('message')->nullable();

            $table->string('output_type', 10)->nullable();             // pdf|zip
            $table->string('output_path')->nullable();                 // relative path in storage/app

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_waybill_print_runs');
    }
};
