<?php

namespace Tests\Feature\Item;

/**
 * /item page markup — ang sourcing chips + worklist table ay nasa CEO view LANG.
 * Direktang nire-render ang view (ang index() ay nangangailangan ng maraming legacy table).
 */
class ItemPageTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout (task badge) — gaya ng BoardroomTestCase.
        \Illuminate\Support\Facades\Schema::create('tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user());
    }

    private function render(bool $effectiveIsCEO): string
    {
        return view('item.index', [
            'pages' => [], 'isCEO' => true, 'isMarketingOIC' => false,
            'viewAs' => $effectiveIsCEO ? 'ceo' : 'marketing', 'effectiveIsCEO' => $effectiveIsCEO,
            'ownerPrivateColsConfig' => null, 'campaignsColsConfig' => null, 'breakevenTargetPct' => 5,
            'colFormatRules' => [], 'campaignsColFormatRules' => [],
            'feeShipping' => null, 'feeCodRate' => null, 'feeVatRate' => null,
        ])->render();
    }

    public function test_hold_and_worklist_load_in_parallel_with_the_item_summary(): void
    {
        $html = $this->render(true);
        $summary = strpos($html, "await fetch('" . route('owner.private.item-summary'));
        $this->assertNotFalse($summary);
        // Nauuna ang hold + worklist sa summary fetch (load() lang ang tumatawag ng hold, isang beses).
        $this->assertLessThan($summary, strpos($html, 'this.loadHold();'));
        $this->assertLessThan($summary, strpos($html, 'this.loadWorklist();'));
        $this->assertSame(1, substr_count($html, 'this.loadHold();'));
        // Sabay-sabay ang images / suppliers / quotes sa init().
        $this->assertStringContainsString('Promise.all([', $html);
        $this->assertStringNotContainsString('await this.loadItemImages();', $html);
    }

    public function test_stock_columns_are_registered_and_rendered_on_item_rows(): void
    {
        $ceo = $this->render(true);
        $ids = ['category', 'stock', 'incoming', 'units_per_day', 'doi', 'order_qty'];
        $catalogIds = array_column(\App\Http\Controllers\OwnerColumnSettingsController::CATALOG['owner_private'], 'id');
        $defaultIds = \App\Http\Controllers\OwnerColumnSettingsController::DEFAULT_VISIBLE['owner_private'];
        foreach ($ids as $id) {
            $this->assertStringContainsString("id:'{$id}'", $ceo);                 // defaultCols()
            $this->assertStringContainsString("col.id==='{$id}'", $ceo);           // item-row cell
            $this->assertContains($id, $catalogIds);
            // Handoff 004: nakatago na by default ang CATEGORY (nasa catalog pa rin).
            $id === 'category' ? $this->assertNotContains($id, $defaultIds) : $this->assertContains($id, $defaultIds);
        }
        $this->assertStringContainsString("col.id==='item_val'", $ceo);
        $this->assertStringContainsString('itemValue(row.item_name)', $ceo);
        // Walang x-html sa bagong cells (x-text lang).
        $this->assertSame(0, substr_count($ceo, 'x-html'));
        // Tooltip + marker texts.
        $this->assertStringContainsString('bilangin', $ceo);
        $this->assertStringContainsString('kulang ', $ceo);
    }

    public function test_stock_loads_in_parallel_before_the_item_summary(): void
    {
        $html = $this->render(true);
        $summary = strpos($html, "await fetch('" . route('owner.private.item-summary'));
        $this->assertLessThan($summary, strpos($html, 'this.loadStock();'));
        $this->assertSame(1, substr_count($html, 'this.loadStock();'));
        $this->assertStringContainsString(route('item.stock'), $html);
    }

    public function test_ceo_item_value_binding_is_not_in_the_marketing_render(): void
    {
        $this->assertStringContainsString('itemValueCeo(row.item_name)', $this->render(true));
        $this->assertStringNotContainsString('itemValueCeo(row.item_name)', $this->render(false));
    }

    public function test_doi_lead_and_order_by_lines_have_plain_tooltips(): void
    {
        $lead = 'Lead = ilang araw bago dumating ang order; palugit = dagdag na araw na reserba';
        $ceo  = $this->render(true);
        $mkt  = $this->render(false);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString($lead, $html);
            $this->assertStringContainsString('Pula: mauubos bago dumating ang order. Dilaw: malapit na. Berde: ok pa.', $html);
            $this->assertStringContainsString('Huling araw na pwedeng umorder para hindi maubusan, base sa lead time', $html);
        }
        $this->assertStringContainsString($lead . ' — i-click para palitan', $ceo);
        $this->assertStringNotContainsString('i-click para palitan', $mkt);
    }

    public function test_category_filter_is_for_every_role_and_inline_edits_are_ceo_only(): void
    {
        $ceo = $this->render(true);
        $mkt = $this->render(false);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString('x-model="categoryFilter"', $html);
            $this->assertStringContainsString('Walang category', $html);
            $this->assertStringContainsString('Walang item sa category na ito.', $html);
            $this->assertSame(0, substr_count($html, 'x-html'));
            // AND-ed pagkatapos ng worklistKeep sa itemGroups().
            $this->assertGreaterThan(
                strpos($html, 'worklistKeep(u.name, u.hold)'),
                strpos($html, 'categoryKeep(u.name)')
            );
        }
        $this->assertStringContainsString('saveCategory(row.item_name', $ceo);
        $this->assertStringContainsString('saveSupplySettings(row.item_name', $ceo);
        $this->assertStringContainsString('+ bagong category', $ceo);
        foreach (['saveCategory(row.item_name', 'saveSupplySettings(row.item_name', '+ bagong category', 'openStockEdit(row.item_name'] as $s) {
            $this->assertStringNotContainsString($s, $mkt);
        }
        $this->assertStringContainsString('openStockEdit(row.item_name', $ceo);
        // Guards: lead/palugit validation, category-empty row ignores ?list= for non-CEO view.
        $this->assertStringContainsString('Lagyan ng numero (0–255) ang lead at palugit.', $ceo);
        foreach ([$ceo, $mkt] as $html) {
            $this->assertStringContainsString("(!effectiveIsCeo || worklist.list === 'lahat')", $html);
        }
    }

    public function test_sourcing_chips_and_worklist_show_in_the_ceo_view_only(): void
    {
        $ceo = $this->render(true);
        $this->assertStringContainsString('setWorklist(c.key)', $ceo);
        $this->assertStringContainsString(route('item.worklist'), $ceo);
        // Isang table lang: ang chips ay filter ng itemGroups(), walang hiwalay na worklist table.
        // ...at HOLD 0 na row (hal. "1 x" na may page lang) ay hindi kasama habang may napiling list.
        $this->assertStringContainsString('worklistKeep(u.name, u.hold)', $ceo);
        $this->assertStringNotContainsString("x-show=\"worklist.list !== 'lahat'\"", $ceo);
        $this->assertStringNotContainsString("x-show=\"!effectiveIsCeo || worklist.list === 'lahat'\"", $ceo);
        $this->assertStringNotContainsString('worklistRows()', $ceo);
        $this->assertStringContainsString('Walang item sa listahang ito.', $ceo);
        // Extra info sa item cell habang may napiling list.
        $this->assertStringContainsString("'kabuuan: '", $ceo);
        $this->assertStringContainsString("'Kulang '", $ceo);

        $mkt = $this->render(false);
        $this->assertStringNotContainsString('setWorklist(c.key)', $mkt);
        $this->assertStringNotContainsString("'kabuuan: '", $mkt);
        $this->assertStringNotContainsString("'Kulang '", $mkt);
        $this->assertStringNotContainsString('Walang item sa listahang ito.', $mkt);
    }
}
