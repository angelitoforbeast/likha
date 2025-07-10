<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameRemarksColumnsInTasksTable extends Migration
{
    public function up()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('remarks_assigned', 'assignee_remarks');
            $table->renameColumn('remarks_creator', 'creator_remarks');
        });
    }

    public function down()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('assignee_remarks', 'remarks_assigned');
            $table->renameColumn('creator_remarks', 'remarks_creator');
        });
    }
}
