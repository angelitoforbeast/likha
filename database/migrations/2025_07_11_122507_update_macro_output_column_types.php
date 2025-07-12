<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateMacroOutputColumnTypes extends Migration
{
    public function up()
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->string('TIMESTAMP', 100)->nullable()->change();
            $table->string('FULL NAME', 255)->nullable()->change();
            $table->string('PHONE NUMBER', 100)->nullable()->change();
            $table->text('ADDRESS')->nullable()->change();
            $table->string('PROVINCE', 255)->nullable()->change();
            $table->string('CITY', 255)->nullable()->change();
            $table->string('BARANGAY', 255)->nullable()->change();
            $table->string('ITEM_NAME', 255)->nullable()->change();
            $table->string('COD', 50)->nullable()->change();
            $table->string('PAGE', 255)->nullable()->change();
            $table->text('ALL USER INPUT')->nullable()->change();
            $table->string('SHOP DETAILS', 500)->nullable()->change();
            $table->string('CXD', 255)->nullable()->change();
            $table->string('AI ANALYZE', 255)->nullable()->change();
            $table->string('HUMAN CHECKER STATUS', 255)->nullable()->change();
            $table->string('RESERVE COLUMN', 255)->nullable()->change();
            $table->string('STATUS', 255)->nullable()->change();
        });
    }

    public function down()
    {
        // optional rollback logic
    }
}
