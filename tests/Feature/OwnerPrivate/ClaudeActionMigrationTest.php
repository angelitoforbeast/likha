<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClaudeActionMigrationTest extends OwnerPrivateTestCase
{
    public function test_migrations_create_both_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('page_day_claude_actions', ['page_key', 'ts_date', 'action', 'reason', 'source', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('page_day_claude_action_logs', ['page_key', 'ts_date', 'old_action', 'new_action', 'old_reason', 'new_reason', 'source', 'edited_at']));
    }

    public function test_same_page_and_date_violates_the_unique_key(): void
    {
        $row = ['page_key' => 'PAGE A', 'ts_date' => '2026-10-03', 'action' => 'x'];
        DB::table('page_day_claude_actions')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('page_day_claude_actions')->insert($row);
    }
}
