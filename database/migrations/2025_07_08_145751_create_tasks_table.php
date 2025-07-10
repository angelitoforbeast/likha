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
        Schema::create('tasks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id')->nullable(); // Assigned user
    $table->string('role_target')->nullable();         // Role assignment
    $table->string('name');                            // Task name
    $table->text('description')->nullable();           // Task details
    $table->string('type');                            // 'everyday', 'manual', 'scheduled'
    $table->boolean('is_repeating')->default(false);   // If task repeats daily
    $table->date('due_date')->nullable();              // Task due date
    $table->string('status')->nullable();              // 'pending', 'done', etc.
    $table->boolean('is_notified')->default(false);    // Notification status
    $table->timestamp('completed_at')->nullable();     // Time task was completed
    $table->text('remarks')->nullable();               // Notes or comments
    $table->unsignedBigInteger('created_by')->nullable(); // Creator (e.g. CEO)
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
