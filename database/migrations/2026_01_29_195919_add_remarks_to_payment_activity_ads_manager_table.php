<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('payment_activity_ads_manager', function (Blueprint $table) {
      $table->text('remarks_1')->nullable()->after('payment_method');
      $table->text('remarks_2')->nullable()->after('remarks_1');
    });
  }

  public function down(): void
  {
    Schema::table('payment_activity_ads_manager', function (Blueprint $table) {
      $table->dropColumn(['remarks_1','remarks_2']);
    });
  }
};
