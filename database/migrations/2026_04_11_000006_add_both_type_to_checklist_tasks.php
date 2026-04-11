<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: modify enum to include 'both'
        DB::statement("ALTER TABLE checklist_tasks MODIFY COLUMN type ENUM('photo','note','any','both') NOT NULL DEFAULT 'any'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE checklist_tasks MODIFY COLUMN type ENUM('photo','note','any') NOT NULL DEFAULT 'any'");
    }
};
