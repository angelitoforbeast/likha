<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFromJntsTable extends Migration
{
    public function up()
    {
        Schema::create('from_jnts', function (Blueprint $table) {
            $table->id();
            $table->string('submission_time')->nullable();
            $table->string('waybill_number')->nullable();
            $table->string('receiver')->nullable();
            $table->string('receiver_cellphone')->nullable();
            $table->string('sender')->nullable();
            $table->string('item_name')->nullable();
            $table->string('cod')->nullable();
            $table->string('remarks')->nullable();
            $table->string('status')->nullable();
            $table->string('signingtime')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('from_jnts');
    }
}
