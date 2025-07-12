<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->text('ADDRESS')->nullable()->change();
            $table->text('AI ANALYZE')->nullable()->change();
            $table->text('ALL USER INPUT')->nullable()->change();
            $table->string('BARANGAY', 255)->nullable()->change();
            $table->string('CITY', 255)->nullable()->change();
            $table->string('COD', 50)->nullable()->change();
            $table->text('CXD')->nullable()->change(); // ❗ was previously NOT NULL
            $table->string('FULL NAME', 255)->nullable()->change();
            $table->string('HUMAN CHECKER STATUS', 255)->nullable()->change();
            $table->string('ITEM_NAME', 255)->nullable()->change();
            $table->string('PAGE', 255)->nullable()->change();
            $table->string('PHONE NUMBER', 100)->nullable()->change();
            $table->string('PROVINCE', 255)->nullable()->change();
            $table->string('RESERVE COLUMN', 255)->nullable()->change();
            $table->text('SHOP DETAILS')->nullable()->change();
            $table->string('STATUS', 255)->nullable()->change();
            $table->string('TIMESTAMP', 100)->nullable()->change();
        });
    }

    public function down()
    {
        // Optional: You can reverse this if you want.
        Schema::table('macro_output', function (Blueprint $table) {
            $table->text('CXD')->nullable(false)->change();
        });
    }
};
