<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->renameColumn('HUMAN CHECKER STATUS', 'APP SCRIPT CHECKER');
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->renameColumn('APP SCRIPT CHECKER', 'HUMAN CHECKER STATUS');
        });
    }
};
