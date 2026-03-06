<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FB Name Blacklist
        Schema::create('fbname_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('fb_name', 255);
            $table->string('reason', 255)->nullable();
            $table->string('host_scope', 20)->default('likha');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['host_scope', 'fb_name']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // Keyword Blacklist
        Schema::create('keyword_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 255);
            $table->string('reason', 255)->nullable();
            $table->string('host_scope', 20)->default('likha');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['host_scope', 'keyword']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_blacklist');
        Schema::dropIfExists('fbname_blacklist');
    }
};
