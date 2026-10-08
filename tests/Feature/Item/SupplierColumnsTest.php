<?php

namespace Tests\Feature\Item;

use App\Models\ItemSupplierQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Isang column kada supplier sa table na may suppliers: ang mga sagot ng server na pinagkukunan ng mga column
 * (ang listahan ng supplier at ang PO ng bawat supplier), at ang text ng mga template nito.
 * Ang mga template test ay nagbabasa ng markup; hindi nila pinapatakbo ang script.
 */
class SupplierColumnsTest extends ItemTestCase
{
    private function ceo(): void
    {
        $this->actingAs(User::where('email', 'ceo@example.test')->first() ?? $this->user());
    }

    private function supplier(string $name): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function quote(string $item, int $supplierId, ?float $price, ?int $moq = null): int
    {
        return DB::table('item_supplier_quotes')->insertGetId([
            'item_key' => ItemSupplierQuote::keyFor($item), 'item_name' => $item, 'supplier_id' => $supplierId,
            'price' => $price, 'moq' => $moq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Isang PO na may isang line. */
    private function po(int $supplierId, string $itemKey, float $cost, string $date = '2026-09-25', ?string $orderNo = null, int $qty = 10): void
    {
        $orderId = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplierId, 'order_date' => $date, 'order_no' => $orderNo, 'status' => 'ordered',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => strtoupper($itemKey),
            'ordered_qty' => $qty, 'unit_cost' => $cost, 'received_qty' => null,
            'line_total' => $qty * $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ang mga PO row ng isang item mula sa GET /item/suppliers. */
    private function poRows(string $itemKey): array
    {
        return $this->getJson('/item/suppliers')->assertOk()->json('suppliers.' . $itemKey) ?? [];
    }

    // ── Ang sagot ng server ───────────────────────────────────────────────────

    public function test_S_34_2_a_supplier_added_on_the_supply_finance_page_is_in_the_quotes_answer_with_its_id(): void
    {
        $this->ceo();
        foreach (['Kelly Trading', 'Albee Co', 'Helen Supply'] as $name) $this->supplier($name);
        $this->assertCount(3, $this->getJson('/item/quotes')->assertOk()->json('suppliers'));

        $this->post(route('finance.supply.suppliers.store'), ['name' => 'Dante Importers'])->assertSessionHasNoErrors();

        $list = $this->getJson('/item/quotes')->assertOk()->json('suppliers');
        $this->assertCount(4, $list);
        $fourth = DB::table('suppliers')->where('name', 'Dante Importers')->value('id');
        $this->assertNotNull($fourth);
        // Bawat row ay may id at pangalan: iyon lang ang kailangan ng mga column.
        $this->assertContains(['id' => (int) $fourth, 'name' => 'Dante Importers'], $list);
        foreach ($list as $row) $this->assertSame(['id', 'name'], array_keys($row));
    }

    public function test_S_36_3_a_discount_or_zero_cost_line_is_not_a_po_of_that_supplier(): void
    {
        $this->ceo();
        $kelly = $this->supplier('Kelly Trading');
        $albee = $this->supplier('Albee Co');
        $this->po($kelly, 'hand grip', 0);
        $this->po($kelly, 'hand grip', -25);
        $this->po($albee, 'hand grip', 17.80);

        $this->assertSame([$albee], array_column($this->poRows('hand grip'), 'supplier_id'));
    }

    public function test_S_36_4_po_rows_carry_the_supplier_id_and_keep_their_other_keys(): void
    {
        $this->ceo();
        // Magkapareho ang pangalan: ang id lang ang nagsasabi kung alin ang may PO.
        $first = $this->supplier('Kelly Trading');
        $second = $this->supplier('Kelly Trading');
        $this->po($second, 'hand grip', 17.80, '2026-09-25', 'PO-0042');

        $rows = $this->poRows('hand grip');
        $this->assertCount(1, $rows);
        $this->assertSame($second, $rows[0]['supplier_id']);
        $this->assertNotSame($first, $rows[0]['supplier_id']);
        // Ang dati nang mga key at value, hindi nagalaw.
        $this->assertSame(
            ['supplier' => 'Kelly Trading', 'unit_cost' => 17.8, 'order_date' => '2026-09-25', 'order_no' => 'PO-0042'],
            array_diff_key($rows[0], ['supplier_id' => true])
        );
        $this->assertSame(['supplier', 'supplier_id', 'unit_cost', 'order_date', 'order_no'], array_keys($rows[0]));

        // Walang ibinabalik sa hindi CEO, gaya ng dati.
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->getJson('/item/suppliers')->assertOk()->assertExactJson(['ok' => true, 'suppliers' => []]);
    }

    public function test_S_36_5_the_po_row_of_a_supplier_is_its_latest_by_order_date_then_line(): void
    {
        $this->ceo();
        $kelly = $this->supplier('Kelly Trading');
        $this->po($kelly, 'hand grip', 16.00, '2026-09-01', 'PO-0001');
        $this->po($kelly, 'hand grip', 17.80, '2026-09-25', 'PO-0002');
        $this->po($kelly, 'hand grip', 18.20, '2026-09-25', 'PO-0003'); // parehong araw: ang huling line ang panalo
        $this->po($kelly, 'hand grip', 15.00, '2026-08-15', 'PO-0000');

        $rows = $this->poRows('hand grip');
        $this->assertCount(1, $rows);
        $this->assertSame([$kelly, 18.2, '2026-09-25', 'PO-0003'], [$rows[0]['supplier_id'], $rows[0]['unit_cost'], $rows[0]['order_date'], $rows[0]['order_no']]);
    }
}
