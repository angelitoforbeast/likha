<?php

namespace Tests\Feature\Item;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier quote history: kapag nagbago ang presyo / MOQ / link, o na-delete ang quote,
 * naitatala ang LUMANG values (sino + kailan). "dati ₱X (date)" = prev_price / prev_date.
 */
class QuoteHistoryTest extends ItemTestCase
{
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = DB::table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function save(array $fields)
    {
        return $this->postJson('/item/quotes', array_merge([
            'item_name' => '1 x HAND GRIP', 'supplier_id' => $this->supplierId,
        ], $fields))->assertOk();
    }

    private function history(): array
    {
        return DB::table('item_supplier_quote_history')->orderBy('id')->get()
            ->map(fn ($r) => [$r->action, $r->price === null ? null : number_format((float) $r->price, 2, '.', ''), $r->moq === null ? null : (int) $r->moq, $r->link])
            ->all();
    }

    public function test_changes_to_price_moq_or_link_write_the_old_values(): void
    {
        $ceo = $this->user();
        $this->actingAs($ceo);
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));
        $this->save(['price' => 100, 'moq' => 10, 'link' => 'https://shop.test/a', 'note' => 'una']);
        $this->assertSame([], $this->history(), 'bagong quote = walang history');

        $steps = [
            // araw        fields na ipapadala                                                => inaasahang BAGONG history row (o null)
            'walang binago, "100.00" = 100' => ['2026-09-05', ['price' => '100.00', 'moq' => 10, 'link' => 'https://shop.test/a', 'note' => 'una'], null],
            'note lang ang binago'          => ['2026-09-10', ['price' => 100, 'moq' => 10, 'link' => 'https://shop.test/a', 'note' => 'iba'], null],
            'presyo 100 → 120'              => ['2026-09-15', ['price' => 120, 'moq' => 10, 'link' => 'https://shop.test/a'], ['update', '100.00', 10, 'https://shop.test/a']],
            'MOQ 10 → 0'                    => ['2026-09-16', ['price' => 120, 'moq' => 0, 'link' => 'https://shop.test/a'], ['update', '120.00', 10, 'https://shop.test/a']],
            'MOQ 0 → wala (null)'           => ['2026-09-17', ['price' => 120, 'link' => 'https://shop.test/a'], ['update', '120.00', 0, 'https://shop.test/a']],
            'link binago'                   => ['2026-09-18', ['price' => 120, 'link' => 'https://shop.test/b'], ['update', '120.00', null, 'https://shop.test/a']],
        ];
        $expected = [];
        foreach ($steps as $label => [$day, $fields, $row]) {
            Carbon::setTestNow(Carbon::parse($day . ' 09:00:00'));
            $this->save($fields);
            if ($row) $expected[] = $row;
            $this->assertSame($expected, $this->history(), $label);
        }

        $h = DB::table('item_supplier_quote_history')->first();
        $this->assertSame('hand grip', $h->item_key);
        $this->assertSame($ceo->id, (int) $h->updated_by);
        // quoted_at = huling save ng quote bago nagbago ang presyo (09-10, ang note-only save)
        $this->assertSame('2026-09-10', substr((string) $h->quoted_at, 0, 10));

        // dati ₱100 (2026-09-10): pinakahuling history na iba ang presyo sa kasalukuyang 120
        $q = $this->getJson('/item/quotes')->assertOk()->json('quotes.hand grip.0');
        $this->assertEquals(100, $q['prev_price']);
        $this->assertSame('2026-09-10', $q['prev_date']);
    }

    public function test_delete_writes_the_old_values_only_for_a_matching_quote(): void
    {
        $this->actingAs($this->user());
        $this->save(['price' => 100, 'moq' => 5, 'link' => null]);
        $id = (int) DB::table('item_supplier_quotes')->value('id');

        $this->postJson('/item/quotes/delete', ['id' => $id, 'item_name' => 'YOGA MAT'])->assertOk();
        $this->assertSame([], $this->history(), 'ibang item_key = walang na-delete, walang history');
        $this->assertSame(1, DB::table('item_supplier_quotes')->count());

        $this->postJson('/item/quotes/delete', ['id' => $id, 'item_name' => '1 x HAND GRIP'])->assertOk();
        $this->assertSame([['delete', '100.00', 5, null]], $this->history());
        $this->assertSame(0, DB::table('item_supplier_quotes')->count());
    }
}
