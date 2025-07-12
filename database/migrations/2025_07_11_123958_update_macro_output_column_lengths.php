<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->text('CXD')->change(); // allow longer text
        $table->text('AI ANALYZE')->change(); // just in case
        $table->text('SHOP DETAILS')->change(); // optional
        $table->text('ALL USER INPUT')->change(); // optional
    });
}

public function down()
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->string('CXD', 255)->change(); // revert if needed
        $table->string('AI ANALYZE', 255)->change();
        $table->string('SHOP DETAILS', 255)->change();
        $table->string('ALL USER INPUT', 255)->change();
    });
}

};
