<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDownloadedMacroOutputLogsTable extends Migration
{
    public function up()
{
    Schema::create('downloaded_macro_output_logs', function (Blueprint $table) {
        $table->id();
        $table->string('timestamp', 50);
        $table->string('page', 255)->nullable();
        $table->string('downloaded_by', 255)->nullable();
        $table->timestamp('downloaded_at')->useCurrent();
        $table->timestamps(); // <-- optional, but common
    });
}


    public function down()
    {
        Schema::dropIfExists('downloaded_macro_output_logs');
    }
}
