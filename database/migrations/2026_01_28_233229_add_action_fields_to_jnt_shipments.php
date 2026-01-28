<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('jnt_shipments', function (Blueprint $table) {
      $table->tinyInteger('create_success')->nullable()->after('success');
      $table->string('create_reason', 50)->nullable()->after('create_success');

      $table->tinyInteger('cancel_success')->nullable()->after('create_reason');
      $table->string('cancel_reason', 50)->nullable()->after('cancel_success');

      $table->string('last_action', 30)->nullable()->after('cancel_reason');
      $table->tinyInteger('last_action_success')->nullable()->after('last_action');
      $table->string('last_action_reason', 50)->nullable()->after('last_action_success');

      $table->longText('last_action_payload')->nullable()->after('last_action_reason');
    });
  }

  public function down(): void {
    Schema::table('jnt_shipments', function (Blueprint $table) {
      $table->dropColumn([
        'create_success','create_reason',
        'cancel_success','cancel_reason',
        'last_action','last_action_success','last_action_reason',
        'last_action_payload',
      ]);
    });
  }
};
