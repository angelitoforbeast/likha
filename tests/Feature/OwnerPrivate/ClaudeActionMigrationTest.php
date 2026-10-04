<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/** Ang Claude pair at ang CEO pair ay iisa ang hugis. */
class ClaudeActionMigrationTest extends OwnerPrivateTestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function tablePairs(): array
    {
        return [
            'claude' => ['page_day_claude_actions', 'page_day_claude_action_logs'],
            'ceo'    => ['page_day_ceo_actions', 'page_day_ceo_action_logs'],
        ];
    }

    #[DataProvider('tablePairs')]
    public function test_migrations_create_both_tables(string $table, string $logTable): void
    {
        $this->assertTrue(Schema::hasColumns($table, ['page_key', 'ts_date', 'action', 'reason', 'source', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns($logTable, ['page_key', 'ts_date', 'old_action', 'new_action', 'old_reason', 'new_reason', 'source', 'edited_at']));
    }

    #[DataProvider('tablePairs')]
    public function test_same_page_and_date_violates_the_unique_key(string $table): void
    {
        $row = ['page_key' => 'PAGE A', 'ts_date' => '2026-10-03', 'action' => 'x'];
        DB::table($table)->insert($row);

        $this->expectException(QueryException::class);
        DB::table($table)->insert($row);
    }
}
