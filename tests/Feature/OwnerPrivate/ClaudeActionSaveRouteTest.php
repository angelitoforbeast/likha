<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /owner/private/claude-action: CEO lang ang puwedeng mag-edit ng Claude Action + Reason.
 */
class ClaudeActionSaveRouteTest extends OwnerPrivateTestCase
{
    private const URL    = '/owner/private/claude-action';
    private const PAGE   = 'page a';
    private const DATE   = '2026-10-03';
    private const SOURCE = 'ceo:CEO User';

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal na fixture para sa item-summary / page-range-breakdown (gaya ng ClaudeActionEndpointTest).
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('ITEM_NAME')->nullable();
            $t->string('PAGE')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('waybill')->nullable();
            $t->string('COD', 50)->nullable();
            $t->date('ts_date')->nullable();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->string('submission_time')->nullable();
        });
        Schema::create('supply_excluded_pages', function (Blueprint $t) {
            $t->id();
            $t->string('page_name', 200)->unique();
            $t->string('reason', 255)->nullable();
            $t->timestamps();
        });
        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order', 'setting_value' => 37, 'effective_date' => '2025-01-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::DATE, 'page_label' => 'PAGE A', 'page_key' => self::PAGE,
            'primary_item' => 'Widget', 'primary_item_key' => 'widget', 'primary_orders' => 5,
            'total_orders_all' => 5, 'primary_mode_cod' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_09_08_134839_create_cogs_table.php',
            'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
            'database/migrations/2026_04_22_120000_create_page_item_settings_table.php',
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
        ]);
    }

    private function save(array $over = [])
    {
        return $this->postJson(self::URL, array_merge(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'action' => 'A1', 'reason' => 'R1'], $over));
    }

    private function seedClaude(): void
    {
        DB::table('page_day_claude_actions')->insert([
            'page_key' => self::PAGE, 'ts_date' => self::DATE, 'action' => 'seed A', 'reason' => 'seed R',
            'source' => 'run-0', 'created_at' => '2026-10-04 08:00:00', 'updated_at' => '2026-10-04 09:30:00',
        ]);
        DB::table('page_day_claude_action_logs')->insert([
            'page_key' => self::PAGE, 'ts_date' => self::DATE, 'new_action' => 'seed A', 'new_reason' => 'seed R',
            'source' => 'run-0', 'edited_at' => '2026-10-04 08:00:00', 'created_at' => '2026-10-04 08:00:00', 'updated_at' => '2026-10-04 08:00:00',
        ]);
    }

    /** @return array<int, array> */
    private function snapshot(): array
    {
        return array_map(fn ($t) => DB::table($t)->get()->all(), ['page_day_claude_actions', 'page_day_claude_action_logs', 'page_day_actions', 'page_day_action_logs']);
    }

    public function test_ceo_inserts_updates_and_clears_with_the_ceo_source_in_the_audit_log(): void
    {
        $this->actingAs($this->user('CEO'));

        $res = $this->save()->assertOk();
        $this->assertSame(['ok' => true, 'status' => 'inserted', 'page_key' => self::PAGE, 'ts_date' => self::DATE,
            'claude_action' => 'A1', 'claude_reason' => 'R1', 'claude_source' => self::SOURCE], array_diff_key($res->json(), ['claude_at' => 1]));
        $this->assertSame(now()->format('Y-m-d H:i'), $res->json('claude_at'));

        $this->save(['action' => ' A2 '])->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('claude_action', 'A2');
        $row = DB::table('page_day_claude_actions')->first();
        $this->assertSame(['A2', 'R1', self::SOURCE], [$row->action, $row->reason, $row->source]);

        $this->save(['action' => '', 'reason' => null])->assertOk()->assertJsonPath('status', 'cleared')
            ->assertJson(['claude_action' => null, 'claude_reason' => null, 'claude_source' => null, 'claude_at' => null]);
        $this->assertSame(0, DB::table('page_day_claude_actions')->count());

        $log = DB::table('page_day_claude_action_logs')->orderBy('id')->get();
        $this->assertSame([self::SOURCE, self::SOURCE, self::SOURCE], $log->pluck('source')->all());
        $this->assertSame([null, 'A1', 'A2'], $log->pluck('old_action')->all());
        $this->assertSame(['A1', 'A2', null], $log->pluck('new_action')->all());
    }

    public function test_an_unchanged_save_writes_no_audit_row(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->save()->assertOk();
        $this->save()->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('claude_action', 'A1');

        $this->assertSame(1, DB::table('page_day_claude_action_logs')->count());
    }

    #[DataProvider('refusedRoles')]
    public function test_other_roles_get_404_and_nothing_is_written(string $role): void
    {
        $this->seedClaude();
        $before = $this->snapshot();

        $this->actingAs($this->user($role, 'other@example.test'));
        $this->save()->assertNotFound();
        $this->save(['action' => '', 'reason' => ''])->assertNotFound();

        $this->assertEquals($before, $this->snapshot());
    }

    /** @return array<string, array{0: string}> */
    public static function refusedRoles(): array
    {
        return ['Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC'], 'Data Encoder' => ['Data Encoder']];
    }

    public function test_a_guest_gets_what_a_guest_gets_on_another_ceo_only_endpoint_and_nothing_is_written(): void
    {
        $this->seedClaude();
        $before = $this->snapshot();

        $expected = $this->getJson('/owner/private/daily')->getStatusCode();
        $this->assertNotSame(200, $expected);
        $this->save()->assertStatus($expected);

        $this->assertEquals($before, $this->snapshot());
    }

    /** @return array<string, array{0: array}> */
    public static function invalidBodies(): array
    {
        return [
            'impossible date'   => [['ts_date' => '2026-02-30']],
            'wrong date format' => [['ts_date' => '03/10/2026']],
            'empty page key'    => [['page_key' => '']],
            'page key too long' => [['page_key' => str_repeat('p', 256)]],
            'action too long'   => [['action' => str_repeat('a', 2001)]],
            'reason too long'   => [['reason' => str_repeat('r', 4001)]],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_input_answers_422_and_writes_nothing(array $over): void
    {
        $this->actingAs($this->user('CEO'));
        $this->save($over)->assertStatus(422);

        $this->assertSame(0, DB::table('page_day_claude_actions')->count());
        $this->assertSame(0, DB::table('page_day_claude_action_logs')->count());
    }

    public function test_text_at_the_exact_limit_is_accepted(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->save(['action' => str_repeat('a', 2000), 'reason' => str_repeat('r', 4000)])->assertOk();

        $this->assertSame([2000, 4000], [mb_strlen(DB::table('page_day_claude_actions')->value('action')), mb_strlen(DB::table('page_day_claude_actions')->value('reason'))]);
    }

    public function test_command_text_edited_by_the_ceo_keeps_run_source_until_the_text_changes(): void
    {
        $this->artisan('owner-private:claude-action', ['page_key' => self::PAGE, 'ts_date' => self::DATE, '--action' => 'A1', '--reason' => 'R1', '--source' => 'run-1'])->assertExitCode(0);

        $this->actingAs($this->user('CEO'));
        $this->save()->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('claude_source', 'run-1');
        $this->assertSame('run-1', DB::table('page_day_claude_actions')->value('source'));
        $this->assertSame(1, DB::table('page_day_claude_action_logs')->count());

        $this->save(['action' => 'A2'])->assertOk();
        $row = DB::table('page_day_claude_actions')->first();
        $this->assertSame(['A2', 'R1', self::SOURCE], [$row->action, $row->reason, $row->source]);

        $log = DB::table('page_day_claude_action_logs')->orderBy('id')->get();
        $this->assertSame(['run-1', self::SOURCE], $log->pluck('source')->all());
        $this->assertSame(['A1', 'A2'], $log->pluck('new_action')->all());
    }

    public function test_a_save_shows_in_the_ceo_summary_and_breakdown_after_a_warm_cache_but_never_to_marketing(): void
    {
        $marker = 'ZX-SAVE-MARKER-4410';
        $summaryUrl = '/owner/private/item-summary?start_date=2026-10-03&end_date=2026-10-03';
        $breakdownUrl = '/owner/private/page-range-breakdown?page_key=page+a&start_date=2026-10-03&end_date=2026-10-03';
        $pageA = fn ($res) => collect($res->json('rows'))->firstWhere('page_key', self::PAGE);

        // Ang version ay time() (1s resolution): ilagay ang luma para hindi mag-banggaan ang lazy-init at ang bump sa iisang segundo.
        Cache::forever('owner_private:cache_version', 'old');
        $this->actingAs($this->user('CEO'));
        $this->assertNull($pageA($this->getJson($summaryUrl)->assertOk())['claude_action']); // warm the cache

        $this->save(['action' => $marker])->assertOk();

        $summary = $this->getJson($summaryUrl)->assertOk();
        $this->assertSame($marker, $pageA($summary)['claude_action']);
        $this->assertSame($marker, collect($this->getJson($breakdownUrl)->assertOk()->json('rows'))->firstWhere('date', self::DATE)['claude_action']);

        auth()->forgetGuards();
        $this->actingAs($this->user('Marketing', 'marketing@example.test'));
        $summary = $this->getJson($summaryUrl)->assertOk();
        $breakdown = $this->getJson($breakdownUrl)->assertOk();
        $this->assertNull($pageA($summary)['claude_action']);
        $this->assertNull(collect($breakdown->json('rows'))->firstWhere('date', self::DATE)['claude_action']);
        $this->assertStringNotContainsString($marker, $summary->getContent() . $breakdown->getContent());
    }

    public function test_the_team_action_tables_are_left_untouched(): void
    {
        DB::table('page_day_actions')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'comment' => 'team note', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        DB::table('page_day_action_logs')->insert(['page_key' => self::PAGE, 'ts_date' => self::DATE, 'new_comment' => 'team note', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);
        $before = [DB::table('page_day_actions')->get()->all(), DB::table('page_day_action_logs')->get()->all()];

        $this->actingAs($this->user('CEO'));
        $this->save()->assertOk();
        $this->save(['action' => '', 'reason' => ''])->assertOk();

        $this->assertEquals($before, [DB::table('page_day_actions')->get()->all(), DB::table('page_day_action_logs')->get()->all()]);
    }

    public function test_script_text_comes_back_as_plain_json_data(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->actingAs($this->user('CEO'));

        $res = $this->save(['action' => $evil])->assertOk();
        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertSame($evil, $res->json('claude_action'));
    }
}
