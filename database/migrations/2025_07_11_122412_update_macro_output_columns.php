<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateMacroOutputColumns extends Migration
{
    public function up()
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropColumn('NAME');              // Remove NAME column
            $table->renameColumn('ITEM', 'ITEM_NAME'); // Rename ITEM to ITEM_NAME
        });
    }

    public function down()
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->string('NAME')->nullable();          // Add back NAME
            $table->renameColumn('ITEM_NAME', 'ITEM');   // Rename back if needed
        });
    }
}
