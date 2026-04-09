<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jnt_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('eccompanyid', 100);
            $table->string('customerid', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_accounts');
    }
};
