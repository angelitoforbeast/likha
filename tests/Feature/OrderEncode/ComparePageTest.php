<?php

namespace Tests\Feature\OrderEncode;

use App\Models\OrderEncode;
use App\Services\OrderEncode\Comparer;
use Carbon\Carbon;

/** /order-encode: ang compare page (read-only) para sa owner at sa analyst. */
class ComparePageTest extends OrderEncodeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $state, array $compareOver = [], array $more = []): OrderEncode
    {
        $fields = array_fill_keys(array_keys(Comparer::FIELDS), 'same');
        $done = $state === 'done';

        return OrderEncode::create($more + [
            'page' => self::PAGE, 'psid' => 1, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => random_int(1000, 9_999_999), 'mode' => 'shadow',
            'status' => 'done', 'input' => ['row' => [], 'fields' => ['date' => '10:15 23-10-2026', 'page' => self::PAGE]],
            'outputs' => ['name' => 'Ana Cruz', 'province' => 'CEBU', 'final' => '✅', 'proceed' => true],
            'steps' => [['step' => 'address_line1', 'status' => 'WROTE_ADDRESS_LINE1', 'model' => 'gpt-4o-mini', 'tokens_in' => 100, 'tokens_out' => 10, 'ms' => 420]],
            'usage' => ['gpt-4o-mini' => ['requests' => 1, 'tokens_in' => 100, 'tokens_out' => 10]],
            'compare_state' => $state === 'new' ? null : $state,
            'compare' => $done ? $compareOver + $fields : null,
            'compare_equal' => $done ? count(array_unique($compareOver + $fields)) === 1 : null,
            'sheet' => $done ? ['database' => array_fill(0, 23, 'x'), 'mirrored' => ['✅', 'PROCEED']] : null,
        ]);
    }

    private ?\App\Models\User $ceo = null;

    private function open(string $query = '')
    {
        $this->ceo ??= $this->user('CEO');

        return $this->actingAs($this->ceo)->get('/order-encode' . $query);
    }

    public function test_S_130_33_only_the_owner_roles_see_the_page(): void
    {
        $this->open()->assertOk();
        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'))->get('/order-encode')->assertOk();
        $this->actingAs($this->user('Marketing', 'm@example.test'))->get('/order-encode')->assertOk();
        $this->actingAs($this->user('Encoder', 'enc@example.test'))->get('/order-encode')->assertNotFound();
        auth()->logout();
        $this->get('/order-encode')->assertRedirect();
    }

    public function test_S_130_34_the_top_has_the_counts_the_percentages_and_the_cost(): void
    {
        $this->mk('done');
        $this->mk('done');
        $this->mk('done', ['province' => 'different', 'w' => 'likha_blank']);
        $this->mk('waiting');
        $this->mk('new');
        $this->mk('sheet_not_done');
        $this->mk('row_mismatch');
        $this->mk('new', more: ['status' => 'failed', 'usage' => null, 'steps' => null]);
        $this->mk('done', more: ['usage' => ['gpt-4o-mini' => ['requests' => 4, 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000], 'gpt-5.2' => ['requests' => 1, 'tokens_in' => 1_000_000, 'tokens_out' => 0]]]);

        $stats = $this->open()->assertOk()->viewData('stats');

        $this->assertSame(9, $stats['orders']);
        $this->assertSame(['compared' => 4, 'equal' => 3, 'waiting' => 2, 'sheet_not_done' => 1, 'row_mismatch' => 1, 'failed' => 1], array_intersect_key($stats, array_flip(['compared', 'equal', 'waiting', 'sheet_not_done', 'row_mismatch', 'failed'])));
        $this->assertSame(75.0, $stats['equal_pct']);
        $this->assertSame(75.0, $stats['field_pct']['province']);
        $this->assertSame(100.0, $stats['field_pct']['name']);
        $this->assertSame(75.0, $stats['field_pct']['w']);
        // 8 order na may gamit: 7 x 1 request + 5 requests = 12 requests; 7 x ($0.000015 + $0.000006) + (0.15+0.60+1.75)
        $this->assertSame(12, $stats['gpt_requests']);
        $this->assertEqualsWithDelta(1.5, $stats['calls_per_order'], 0.001);
        $this->assertEqualsWithDelta((7 * (100 * 0.15 + 10 * 0.60) / 1e6 + 2.5) / 8, $stats['cost_per_order'], 0.0001);
    }

    public function test_S_130_35_the_table_shows_both_values_side_by_side_and_marks_the_differences(): void
    {
        $o = $this->mk('done', ['province' => 'different'], ['outputs' => ['name' => 'Ana Cruz', 'province' => 'CEBU', 'final' => '✅', 'proceed' => true]]);
        $db = array_fill(0, 23, '');
        $db[4] = 'ABRA';
        $o->update(['sheet' => ['database' => $db, 'mirrored' => ['✅', 'PROCEED']]]);

        $r = $this->open()->assertOk();

        $rows = $r->viewData('rows');
        $this->assertSame(['CEBU', 'ABRA', 'different'], [$rows[0]['cells']['province']['likha'], $rows[0]['cells']['province']['sheet'], $rows[0]['cells']['province']['result']]);
        $r->assertSee('CEBU')->assertSee('ABRA')->assertSee('bg-red-100', false);
        $r->assertSee('address_line1: WROTE_ADDRESS_LINE1 (gpt-4o-mini, 100/10 tokens, 420 ms)', false);
    }

    public function test_S_130_36_chat_text_is_escaped(): void
    {
        $evil = '<script>alert("x")</script>';
        $o = $this->mk('done', ['name' => 'different'], ['outputs' => ['name' => $evil, 'province' => 'CEBU', 'final' => '✅', 'proceed' => true],
            'steps' => [['step' => 'address_line1', 'status' => 'ERR:' . $evil, 'raw' => $evil]]]);

        $html = $this->open()->assertOk()->getContent();

        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertSame($o->id, $this->open()->viewData('rows')[0]['id']);
    }

    public function test_S_130_37_the_filters_by_date_page_differences_and_field(): void
    {
        $same = $this->mk('done');
        $diffProv = $this->mk('done', ['province' => 'different']);
        $diffName = $this->mk('done', ['name' => 'sheet_blank']);
        $other = $this->mk('done', ['province' => 'different'], ['page' => 'Page Dos']);
        $old = $this->mk('done', [], ['created_at' => '2026-10-10 09:00:00']);

        $ids = fn (string $q) => collect($this->open($q)->viewData('rows'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$same->id, $diffProv->id, $diffName->id, $other->id], $ids('?from=2026-10-23&to=2026-10-23'));
        $this->assertSame([$diffProv->id, $diffName->id, $other->id], $ids('?from=2026-10-23&to=2026-10-23&only_diff=1'));
        $this->assertSame([$diffProv->id, $other->id], $ids('?from=2026-10-23&to=2026-10-23&field=province'));
        $this->assertSame([$diffProv->id], $ids('?from=2026-10-23&to=2026-10-23&field=province&page=Page+Uno'));
        $this->assertContains($old->id, $ids('?from=2026-10-01&to=2026-10-23'));
        $this->assertSame([], $ids('?from=2026-10-23&to=2026-10-23&field=nonsense&page=Nobody'));
    }

    public function test_S_130_38_the_page_is_read_only(): void
    {
        $this->mk('done');
        $count = OrderEncode::count();

        $this->open();
        $this->actingAs($this->ceo)->post('/order-encode')->assertStatus(405);
        $this->assertSame($count, OrderEncode::count());
    }
}
