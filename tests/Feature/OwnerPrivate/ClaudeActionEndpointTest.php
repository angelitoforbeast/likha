<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Claude Action columns sa /owner/private/item-summary + page-range-breakdown: CEO lang ang may value.
 */
class ClaudeActionEndpointTest extends OwnerPrivateTestCase
{
    private const MARKER = 'ZX-CLAUDE-MARKER-7731';

    protected function setUp(): void
    {
        parent::setUp();

        // macro_output / from_jnts = minimal na legacy columns (MySQL-only ang ts_date trigger migration).
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
        // Hand-made: ang migration nito ay may kasamang update sa item_class_rules.
        Schema::create('supply_excluded_pages', function (Blueprint $t) {
            $t->id();
            $t->string('page_name', 200)->unique();
            $t->string('reason', 255)->nullable();
            $t->timestamps();
        });

        // Kailangan ng itemSummary ang tatlong fee rate; ang migration ay naka-seed na ng dalawa (scope 'likha'), kulang ang shipping.
        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order', 'setting_value' => 37, 'effective_date' => '2025-01-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Dalawang page sa roster ng 2026-10-03 (daily_page_primary_item); Claude note ang page a lang.
        foreach (['page a', 'page b'] as $key) {
            DB::table('daily_page_primary_item')->insert([
                'ts_date' => '2026-10-03', 'page_label' => strtoupper($key), 'page_key' => $key,
                'primary_item' => 'Widget', 'primary_item_key' => 'widget', 'primary_orders' => 5,
                'total_orders_all' => 5, 'primary_mode_cod' => 500,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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

    private function claudeNote(string $date, string $action, string $reason, string $source): void
    {
        DB::table('page_day_claude_actions')->insert([
            'page_key' => 'page a', 'ts_date' => $date, 'action' => $action, 'reason' => $reason,
            'source' => $source, 'created_at' => '2026-10-04 08:00:00', 'updated_at' => '2026-10-04 09:30:00',
        ]);
    }

    /** @return array{0: array, 1: array, 2: array} summary row page a, summary row page b, breakdown rows by date */
    private function fetch(string $role): array
    {
        $this->actingAs($this->user($role, strtolower(str_replace(' ', '', $role)) . '@example.test'));

        $summary = $this->getJson('/owner/private/item-summary?start_date=2026-10-03&end_date=2026-10-03&refresh=1');
        $breakdown = $this->getJson('/owner/private/page-range-breakdown?page_key=page+a&start_date=2026-10-02&end_date=2026-10-03');
        $summary->assertOk();
        $breakdown->assertOk();
        $this->lastBodies = $summary->getContent() . $breakdown->getContent();

        $byPage = collect($summary->json('rows'))->keyBy('page_key');
        $this->assertTrue($byPage->has('page a') && $byPage->has('page b'), 'roster fixture did not produce both pages');

        return [$byPage['page a'], $byPage['page b'], collect($breakdown->json('rows'))->keyBy('date')->all()];
    }

    private string $lastBodies = '';

    public function test_ceo_gets_the_claude_values_and_empty_page_days_get_nulls(): void
    {
        $this->claudeNote('2026-10-03', 'taasan ang budget', "ROAS 4.1\nCPP 80", 'run-1');
        $this->claudeNote('2026-10-02', 'ibaba', 'walang orders', 'run-1');

        [$a, $b, $days] = $this->fetch('CEO');

        $expected = ['claude_action' => 'taasan ang budget', 'claude_reason' => "ROAS 4.1\nCPP 80", 'claude_at' => '2026-10-04 09:30', 'claude_source' => 'run-1'];
        $nulls    = ['claude_action' => null, 'claude_reason' => null, 'claude_at' => null, 'claude_source' => null];

        $this->assertSame($expected, array_intersect_key($a, $expected));
        $this->assertSame($nulls, array_intersect_key($b, $nulls));
        $this->assertTrue($days['2026-10-03']['has_data']);
        $this->assertSame($expected, array_intersect_key($days['2026-10-03'], $expected));
        // walang data na araw (ibang payload site) may note pa rin
        $this->assertFalse($days['2026-10-02']['has_data']);
        $this->assertSame('ibaba', $days['2026-10-02']['claude_action']);
    }

    #[DataProvider('nonCeoRoles')]
    public function test_non_ceo_never_receives_the_claude_text(string $role): void
    {
        $this->claudeNote('2026-10-03', self::MARKER . '-action', self::MARKER . '-reason', self::MARKER . '-source');
        $this->claudeNote('2026-10-02', self::MARKER . '-action2', self::MARKER . '-reason2', self::MARKER . '-source2');

        [$a, , $days] = $this->fetch($role);

        foreach ([$a, $days['2026-10-03'], $days['2026-10-02']] as $row) {
            foreach (['claude_action', 'claude_reason', 'claude_at', 'claude_source'] as $key) {
                $this->assertNull($row[$key] ?? null, "$key leaked to $role");
            }
        }
        $this->assertStringNotContainsString(self::MARKER, $this->lastBodies);
    }

    /** Ang item-summary ay naka-cache per role: ang naka-cache na sagot ng CEO ay hindi dapat maihain sa iba. */
    public function test_a_cached_ceo_summary_is_not_served_to_marketing(): void
    {
        $this->claudeNote('2026-10-03', self::MARKER . '-action', self::MARKER . '-reason', self::MARKER . '-source');
        $url = '/owner/private/item-summary?start_date=2026-10-03&end_date=2026-10-03';

        $this->actingAs($this->user('CEO'));
        $this->getJson($url)->assertOk();
        $hit = $this->getJson($url)->assertOk();
        $this->assertSame('hit', $hit->json('_cache'));
        $this->assertStringContainsString(self::MARKER, $hit->getContent());

        auth()->forgetGuards();
        $this->actingAs($this->user('Marketing', 'marketing@example.test'));
        $this->assertStringNotContainsString(self::MARKER, $this->getJson($url)->assertOk()->getContent());
    }

    /** @return array<string, array{0: string}> */
    public static function nonCeoRoles(): array
    {
        return ['Marketing - OIC' => ['Marketing - OIC'], 'Marketing' => ['Marketing']];
    }

    /**
     * Ang JSON ng Laravel ay hindi nag-hex-escape ng '<' (slash lang ang escaped), kaya ang proteksyon ay:
     * JSON content-type (hindi HTML) + data lang ang text, at textContent/escape sa pag-render ng page.
     */
    public function test_script_text_is_returned_as_plain_json_data(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->claudeNote('2026-10-03', $evil, $evil, $evil);
        $this->actingAs($this->user('CEO'));

        foreach ([
            '/owner/private/item-summary?start_date=2026-10-03&end_date=2026-10-03&refresh=1',
            '/owner/private/page-range-breakdown?page_key=page+a&start_date=2026-10-03&end_date=2026-10-03',
        ] as $url) {
            $res = $this->getJson($url)->assertOk();
            $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
            $row = collect($res->json('rows'))->first(fn ($r) => ($r['page_key'] ?? null) === 'page a' || ($r['date'] ?? null) === '2026-10-03');
            $this->assertSame($evil, $row['claude_action']);
            $this->assertSame($evil, $row['claude_reason']);
        }
    }
}
