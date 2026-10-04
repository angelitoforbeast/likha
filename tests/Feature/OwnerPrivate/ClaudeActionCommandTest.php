<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ClaudeActionCommandTest extends OwnerPrivateTestCase
{
    private const PAGE = 'PAGE A';
    private const DATE = '2026-10-03';

    private function run_(array $args)
    {
        return $this->artisan('owner-private:claude-action', array_merge(['page_key' => self::PAGE, 'ts_date' => self::DATE], $args));
    }

    private function claudeRow(): ?object
    {
        return DB::table('page_day_claude_actions')->first();
    }

    public function test_insert_writes_the_row_and_one_audit_row(): void
    {
        $this->run_(['--action' => '  taasan ang budget ', '--reason' => 'ROAS 4.1', '--source' => 'run-1'])
            ->expectsOutput('inserted Claude action for PAGE A 2026-10-03')
            ->assertExitCode(0);

        $row = $this->claudeRow();
        $this->assertSame('taasan ang budget', $row->action);
        $this->assertSame('ROAS 4.1', $row->reason);
        $this->assertSame('run-1', $row->source);

        $log = DB::table('page_day_claude_action_logs')->get();
        $this->assertCount(1, $log);
        $this->assertNull($log[0]->old_action);
        $this->assertSame('taasan ang budget', $log[0]->new_action);
        $this->assertSame('ROAS 4.1', $log[0]->new_reason);
        $this->assertNotNull($log[0]->edited_at);
    }

    public function test_update_logs_old_and_new_and_keeps_fields_not_passed(): void
    {
        $this->run_(['--action' => 'A1', '--reason' => 'R1', '--source' => 's'])->assertExitCode(0);
        $this->run_(['--action' => 'A2'])->expectsOutput('updated Claude action for PAGE A 2026-10-03')->assertExitCode(0);

        $row = $this->claudeRow();
        $this->assertSame(['A2', 'R1', 's'], [$row->action, $row->reason, $row->source]);

        $log = DB::table('page_day_claude_action_logs')->orderByDesc('id')->first();
        $this->assertSame(['A1', 'A2', 'R1', 'R1'], [$log->old_action, $log->new_action, $log->old_reason, $log->new_reason]);
        $this->assertSame(2, DB::table('page_day_claude_action_logs')->count());
    }

    public function test_same_values_again_write_no_audit_row(): void
    {
        $this->run_(['--action' => 'A1', '--reason' => 'R1'])->assertExitCode(0);
        $this->run_(['--action' => 'A1', '--reason' => 'R1'])->expectsOutput('unchanged Claude action for PAGE A 2026-10-03')->assertExitCode(0);

        $this->assertSame(1, DB::table('page_day_claude_action_logs')->count());
    }

    public function test_clear_deletes_the_row_and_logs_it(): void
    {
        $this->run_(['--action' => 'A1', '--reason' => 'R1'])->assertExitCode(0);
        $this->run_(['--clear' => true])->expectsOutput('cleared Claude action for PAGE A 2026-10-03')->assertExitCode(0);

        $this->assertNull($this->claudeRow());
        $log = DB::table('page_day_claude_action_logs')->orderByDesc('id')->first();
        $this->assertSame(['A1', null, 'R1', null], [$log->old_action, $log->new_action, $log->old_reason, $log->new_reason]);

        // walang row na buburahin → walang bagong audit row
        $this->run_(['--clear' => true])->assertExitCode(0);
        $this->assertSame(2, DB::table('page_day_claude_action_logs')->count());
    }

    /** @return array<string, array{0: array, 1: array}> */
    public static function rejectedInputs(): array
    {
        return [
            'impossible date'      => [['ts_date' => '2026-13-40'], ['--action' => 'x']],
            'wrong date format'    => [['ts_date' => '10/04/2026'], ['--action' => 'x']],
            'feb 30'               => [['ts_date' => '2026-02-30'], ['--action' => 'x']],
            'blank page key'       => [['page_key' => '   '], ['--action' => 'x']],
            'page key too long'    => [['page_key' => str_repeat('p', 256)], ['--action' => 'x']],
            'action too long'      => [[], ['--action' => str_repeat('a', 2001)]],
            'reason too long'      => [[], ['--reason' => str_repeat('r', 4001)]],
            'source too long'      => [[], ['--action' => 'x', '--source' => str_repeat('s', 256)]],
            'multibyte too long'   => [[], ['--action' => str_repeat('ñ', 2001)]],
            'nothing to do'        => [[], []],
            'clear with action'    => [[], ['--clear' => true, '--action' => 'x']],
            'clear with reason'    => [[], ['--clear' => true, '--reason' => 'x']],
            'both texts blank'     => [[], ['--action' => '  ', '--reason' => '']],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function test_invalid_input_is_rejected_without_a_write(array $positional, array $options): void
    {
        $this->artisan('owner-private:claude-action', array_merge(['page_key' => self::PAGE, 'ts_date' => self::DATE], $positional, $options))
            ->assertFailed();

        $this->assertSame(0, DB::table('page_day_claude_actions')->count());
        $this->assertSame(0, DB::table('page_day_claude_action_logs')->count());
    }

    public function test_text_at_the_exact_limit_is_accepted(): void
    {
        $this->run_(['--action' => str_repeat('ñ', 2000), '--reason' => str_repeat('r', 4000)])->assertExitCode(0);

        $this->assertSame(2000, mb_strlen($this->claudeRow()->action));
    }

    public function test_a_write_bumps_the_cache_version_and_a_no_op_does_not(): void
    {
        Cache::forever('owner_private:cache_version', 'old');

        $this->run_(['--action' => 'A1'])->assertExitCode(0);
        $this->assertNotSame('old', Cache::get('owner_private:cache_version'));

        Cache::forever('owner_private:cache_version', 'old');
        $this->run_(['--action' => 'A1'])->assertExitCode(0);
        $this->assertSame('old', Cache::get('owner_private:cache_version'));
    }

    /** Ang team pair at ang CEO pair (may row ang CEO note para sa parehong page + date) ay hindi hinahawakan ng command. */
    public function test_the_team_and_ceo_action_tables_are_left_untouched(): void
    {
        DB::table('page_day_actions')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'comment' => 'team note', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        DB::table('page_day_action_logs')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'new_comment' => 'team note', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        DB::table('page_day_ceo_actions')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'action' => 'ceo A', 'reason' => 'ceo R', 'source' => 'ceo:Busing', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        DB::table('page_day_ceo_action_logs')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'new_action' => 'ceo A', 'new_reason' => 'ceo R', 'source' => 'ceo:Busing', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        $tables = ['page_day_actions', 'page_day_action_logs', 'page_day_ceo_actions', 'page_day_ceo_action_logs'];
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->get()->all(), $tables);
        $before = $snapshot();

        $this->run_(['--action' => 'A1', '--reason' => 'R1'])->assertExitCode(0);
        $this->run_(['--action' => 'A2'])->assertExitCode(0);
        $this->run_(['--clear' => true])->assertExitCode(0);

        $this->assertEquals($before, $snapshot());
    }

    public function test_a_page_key_no_page_has_is_saved_with_a_note_on_the_same_line(): void
    {
        // Ang roster (daily_page_primary_item) ang may alam ng tunay na page_key: lowercase + trimmed.
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php'], '--force' => true]);
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => '2026-10-01', 'page_label' => 'PAGE A', 'page_key' => 'page a',
            'primary_item' => 'Widget', 'primary_item_key' => 'widget', 'primary_orders' => 5, 'total_orders_all' => 5,
        ]);

        $this->artisan('owner-private:claude-action', ['page_key' => 'page a', 'ts_date' => self::DATE, '--action' => 'A1'])
            ->expectsOutput('inserted Claude action for page a 2026-10-03')
            ->assertExitCode(0);
        $this->artisan('owner-private:claude-action', ['page_key' => 'Page A', 'ts_date' => '2026-10-02', '--action' => 'A1'])
            ->expectsOutput('inserted Claude action for Page A 2026-10-02 (note: no page has exactly this page_key, so it will not show on the page)')
            ->assertExitCode(0);
    }
}
