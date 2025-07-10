<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN user_id BIGINT UNSIGNED NULL AFTER id");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN name VARCHAR(255) NULL AFTER user_id");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN role_target VARCHAR(255) NULL AFTER name");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN task_name VARCHAR(255) NULL AFTER role_target");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN description TEXT NULL AFTER task_name");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN type VARCHAR(255) NULL AFTER description");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN is_repeating TINYINT(1) NULL AFTER type");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN priority_level VARCHAR(255) NULL AFTER is_repeating");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN due_date DATE NULL AFTER priority_level");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status VARCHAR(255) NULL AFTER due_date");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN is_notified TINYINT(1) NULL AFTER status");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN completed_at DATETIME NULL AFTER is_notified");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN remarks_assigned TEXT NULL AFTER completed_at");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN remarks_created_by TEXT NULL AFTER remarks_assigned");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN created_by BIGINT UNSIGNED NULL AFTER remarks_created_by");
    }

    public function down(): void
    {
        // You may leave this blank unless you want to reverse the exact order
    }
};
