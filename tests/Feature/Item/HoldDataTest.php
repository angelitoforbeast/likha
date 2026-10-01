<?php

namespace Tests\Feature\Item;

/**
 * /item/data — HOLD per item + page.
 * HOLD = macro_output row na may waybill na WALA pa sa from_jnts.
 */
class HoldDataTest extends ItemTestCase
{
    /** item_name → ['total' => n, 'pages' => [page => n]] mula sa JSON. */
    private function holdMap(array $json): array
    {
        $map = [];
        foreach ($json['items'] as $it) {
            $pages = [];
            foreach ($it['pages'] as $p) $pages[$p['page']] = $p['total_hold'];
            $map[$it['item_name']] = ['total' => $it['total_hold'], 'pages' => $pages];
        }
        return $map;
    }

    public function test_counts_held_waybills_per_item_and_page_without_a_date_range(): void
    {
        $this->order('1 x HAND GRIP', 'Page A', 'W1');
        $this->order('1 x HAND GRIP', 'Page A', 'W2');
        $this->order('1 x HAND GRIP', 'Page A', 'W3');   // shipped → hindi HOLD
        $this->shipped('W3');
        $this->order('1 x HAND GRIP', 'Page B', 'W4');
        $this->order('YOGA MAT', 'Page A', '');          // walang waybill
        $this->order('YOGA MAT', 'Page A', '   ');       // blangkong waybill
        $this->order('YOGA MAT', 'Page A', null);
        $this->order('YOGA MAT', 'Page A', 'W6', '2026-09-10', null);
        $this->order('2 x HAND GRIP', 'Page A', 'W5', '2026-09-10', 'CANNOT PROCEED'); // cancelled → hindi HOLD

        $json = $this->actingAs($this->user())->getJson('/item/data')->assertOk()->json();

        $this->assertSame([
            '1 x HAND GRIP' => ['total' => 3, 'pages' => ['Page A' => 2, 'Page B' => 1]],
            'YOGA MAT'      => ['total' => 1, 'pages' => ['Page A' => 1]],
        ], $this->holdMap($json));
        $this->assertSame(4, $json['total_hold']);
        $this->assertSame(2, $json['total_items']);
    }

    public function test_hold_excludes_cancelled_statuses_only(): void
    {
        $cases = [
            // STATUS            => binibilang ba sa HOLD?
            'null'               => [null, true],
            'blank'              => ['', true],
            'PROCEED'            => ['PROCEED', true],
            'CANNOT PROCEED'     => ['CANNOT PROCEED', false],
            'cannot proceed'     => ['cannot proceed', false],
            'CANNOT_PROCEED'     => ['CANNOT_PROCEED', false],
            ' odz '              => [' odz ', false],
            'ODZ'                => ['ODZ', false],
        ];
        $i = 0;
        foreach ($cases as $label => [$status]) {
            $this->order('ITEM ' . $label, 'Page A', 'S' . (++$i), '2026-09-10', $status);
        }

        $json = $this->actingAs($this->user())->getJson('/item/data')->assertOk()->json();
        $held = array_keys($this->holdMap($json));
        sort($held);

        $this->assertSame(['ITEM PROCEED', 'ITEM blank', 'ITEM null'], $held);
    }

    public function test_date_range_filters_on_order_date_inclusive(): void
    {
        $this->order('HAND GRIP', 'Page A', 'D1', '2026-08-31');   // bago ang range
        $this->order('HAND GRIP', 'Page A', 'D2', '2026-09-01');   // unang araw
        $this->order('HAND GRIP', 'Page A', 'D3', '2026-09-30');   // huling araw
        $this->order('HAND GRIP', 'Page A', 'D4', '2026-10-01');   // lampas
        \Illuminate\Support\Facades\DB::table('macro_output')->insert([   // sirang TIMESTAMP → walang ts_date
            'ITEM_NAME' => 'HAND GRIP', 'PAGE' => 'Page A', 'TIMESTAMP' => 'kahapon', 'STATUS' => 'PROCEED', 'waybill' => 'D5', 'ts_date' => null,
        ]);

        $json = $this->actingAs($this->user())
            ->getJson('/item/data?date_range=' . urlencode('2026-09-01 to 2026-09-30'))
            ->assertOk()->json();

        $this->assertSame(['HAND GRIP' => ['total' => 2, 'pages' => ['Page A' => 2]]], $this->holdMap($json));
    }
}
