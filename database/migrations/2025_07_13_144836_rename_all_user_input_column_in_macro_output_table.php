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
    if (DB::getDriverName() === 'mysql') {
        DB::statement('ALTER TABLE macro_output RENAME COLUMN `ALL USER INPUT` TO all_user_input');
    } elseif (DB::getDriverName() === 'pgsql') {
        DB::statement('ALTER TABLE macro_output RENAME COLUMN "ALL USER INPUT" TO all_user_input');
    }
}

public function down()
{
    if (DB::getDriverName() === 'mysql') {
        DB::statement('ALTER TABLE macro_output RENAME COLUMN all_user_input TO `ALL USER INPUT`');
    } elseif (DB::getDriverName() === 'pgsql') {
        DB::statement('ALTER TABLE macro_output RENAME COLUMN all_user_input TO "ALL USER INPUT"');
    }
}


};
