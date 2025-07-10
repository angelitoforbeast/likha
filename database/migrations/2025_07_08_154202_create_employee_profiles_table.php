<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmployeeProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name'); // name comes right after user_id

            $table->string('employee_code')->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('employment_type')->nullable(); // Regular, Contractual, etc.
            $table->decimal('salary', 10, 2)->nullable();
            $table->date('date_hired')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('gender')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number')->nullable();
            $table->string('sss_number')->nullable();
            $table->string('tin_number')->nullable();
            $table->string('philhealth_number')->nullable();
            $table->string('pagibig_number')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('status')->nullable(); // Active, Resigned, etc.
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
}
