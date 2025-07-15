<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('everyday_tasks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('task_name');
    $table->text('description')->nullable();
    $table->string('type')->nullable();
    $table->boolean('is_repeating')->default(true);
    $table->integer('priority_score')->default(1);
    $table->time('due_time')->nullable(); // ✅ added due_time
    $table->unsignedBigInteger('created_by')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('everyday_tasks');
    }
};
