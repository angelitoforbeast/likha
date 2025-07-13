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
        $table->renameColumn('ALL USER INPUT', 'all_user_input');
    });
}

public function down()
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->renameColumn('all_user_input', 'ALL USER INPUT');
    });
}

};
