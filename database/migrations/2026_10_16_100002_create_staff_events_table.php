<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_events')) {
            return;
        }

        // Isang row kada matagumpay na write (POST/PUT/PATCH/DELETE) ng naka-login na staff, at kada
        // login/logout. Walang body o query string: route name, method, status at id lang.
        Schema::create('staff_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();          // kopya ng employee_profiles.role noong oras na iyon
            $table->string('kind', 191);                 // route name, o "METHOD path-pattern" kapag walang pangalan
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ref', 64)->nullable();       // id-like na route parameter
            $table->dateTime('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_events');
    }
};
