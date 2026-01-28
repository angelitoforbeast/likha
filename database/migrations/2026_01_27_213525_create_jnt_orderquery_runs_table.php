<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jnt_orderquery_runs')) return;

        Schema::create('jnt_orderquery_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('upload_date')->nullable();
            $table->string('page')->nullable();

            $table->string('status', 30)->default('queued'); // queued|running|done|failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->text('last_error')->nullable();

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['upload_date', 'page']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_orderquery_runs');
    }
};
