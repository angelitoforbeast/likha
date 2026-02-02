<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jnt_checker_run_items', function (Blueprint $table) {
            // ✅ para ma-show sa UI yung "actual payload sent" at "actual response received"
            $table->longText('api_request_payload')->nullable();
            $table->longText('api_response_payload')->nullable();

            $table->boolean('api_success')->nullable()->index();
            $table->string('api_reason')->nullable(); // e.g. B063 or message
        });
    }

    public function down(): void
    {
        Schema::table('jnt_checker_run_items', function (Blueprint $table) {
            $table->dropColumn([
                'api_request_payload',
                'api_response_payload',
                'api_success',
                'api_reason',
            ]);
        });
    }
};
