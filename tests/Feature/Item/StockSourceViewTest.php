<?php

namespace Tests\Feature\Item;

/**
 * /item (bagong view, To order): ang pinanggalingan ng stock (Nexus sync, returned source, hindi-tugmang pangalan),
 * ang babala sa tabi ng Qty to order, at ang breakdown + Nexus cross-check sa details. English; x-text lang.
 * Direktang nire-render ang view, gaya ng ItemPageTest.
 */
class StockSourceViewTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::create('tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user());
    }

    private function render(bool $effectiveIsCEO, bool $layoutOld = false): string
    {
        return view('item.index', [
            'layoutOld' => $layoutOld,
            'pages' => [], 'isCEO' => true, 'isMarketingOIC' => false,
            'viewAs' => $effectiveIsCEO ? 'ceo' : 'marketing', 'effectiveIsCEO' => $effectiveIsCEO, 'canSupplierWork' => $effectiveIsCEO,
            'ownerPrivateColsConfig' => null, 'campaignsColsConfig' => null, 'breakevenTargetPct' => 5,
            'colFormatRules' => [], 'campaignsColFormatRules' => [],
            'feeShipping' => null, 'feeCodRate' => null, 'feeVatRate' => null,
        ])->render();
    }

    private function between(string $html, string $from, string $to): string
    {
        $a = strpos($html, $from);
        $this->assertNotFalse($a, $from);
        $b = strpos($html, $to, $a);
        $this->assertNotFalse($b, $to);

        return substr($html, $a, $b - $a);
    }

    public function test_S_220_2_1_2_3_2_6_2_7_the_stock_source_line_shows_sync_state_for_every_role(): void
    {
        foreach ([true, false] as $ceo) {
            $html = $this->render($ceo);
            $this->assertStringContainsString('this.stock.meta = j.stock_meta || null;', $html);
            $line = $this->between($html, 'id="il-stock-src"', '</template>');
            $this->assertStringContainsString('x-text="stockSourceText()"', $line);
            foreach (["'Nexus last synced '", "'Nexus not synced yet'", "'Nexus token needs fixing'",
                      "'Nexus not synced for more than 3 hours: the numbers may be old'",
                      "'Nexus not configured: stock = PO received − shipped'",
                      "'Nexus token missing — stock shows the old PO count'", 'this.stock.meta?.nexus?.previously_synced',
                      "'Nexus returned nothing for ' + n.unconfirmed_empty_days.join(', ') + '; old numbers kept'",
                      "'Nexus daily 7-day pass skipped (locked) at ' + n.daily_pass.at",
                      "n.last_error.kind === 'skipped'", "n.last_error.message + ' (their old numbers are kept)'",
                      "'Returned is not available (Nexus RTS)'", "'Unknown returned source setting: using J&T'"] as $text) {
                $this->assertStringContainsString($text, $html, $text);
            }
        }
    }

    public function test_S_220_3_8_unmatched_nexus_names_are_collapsed_with_a_count_and_escaped(): void
    {
        $html = $this->render(true);
        $block = $this->between($html, '<details class="il-unmatched"', '</details>');

        $this->assertStringContainsString("x-text=\"num(stock.meta.nexus.unmatched_count) + ' Nexus names not matched'\"", $block);
        $this->assertStringContainsString('x-text="u.name"', $block);
        $this->assertStringContainsString("x-text=\"num(u.pieces) + ' pcs'\"", $block);
        $this->assertStringContainsString('x-show="u.ambiguous"', $block);
        $this->assertStringContainsString(' · ambiguous</span>', $block);
        $this->assertStringNotContainsString(' open', substr($block, 0, 40), 'naka-collapse');
    }

    public function test_S_220_8_3_the_stale_and_unmatched_warning_is_next_to_qty_to_order(): void
    {
        $th = $this->between($this->render(true), "@click=\"sb('order_qty')\"", '</th>');

        $this->assertStringContainsString('class="il-warn-mark"', $th);
        $this->assertStringContainsString('x-show="stockWarnings().length"', $th);
        $this->assertStringContainsString(":title=\"stockWarnings().join(' · ')\"", $th);
    }

    public function test_S_220_7_and_6_10_the_details_show_the_breakdown_and_the_nexus_cross_check(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('x-text="stockBreakdown(G.item_name)"', $html);
        $this->assertStringContainsString('x-text="nexusCrossText(G.item_name)"', $html);
        foreach (["'from ' + S.stock_start + ': stock-in '", "' + returned '", "' − shipped '", "' (as of '", "' (old)'",
                  "' (an older Nexus name left out)'", 'S.nexus_remaining_as_of', 'S.nexus_remaining_stale'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Malalaking numero: lahat ng numero ay dumadaan sa num() (toLocaleString).
        $this->assertMatchesRegularExpression('/stockBreakdown\(name\)\{[^}]*this\.num\(/s', $html);
    }

    public function test_S_222_6_1_the_nexus_count_is_labelled_boxes_not_yet_released_with_no_difference(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString("'Nexus boxes not yet released: '", $html);
        $this->assertStringContainsString("'Whole lots (boxes) still on the shelf in Nexus, not yet released to the floor: not all pieces on hand'", $html);
        $this->assertStringContainsString(':title="nexusCrossTip()" x-text="nexusCrossText(G.item_name)"', $html);
        foreach (['Nexus on shelf', ', difference ', 'nexus_diff', 'so a difference is expected'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function test_S_222_3_not_in_nexus_shows_instead_of_a_number_and_the_quantity_is_muted_with_its_tooltip(): void
    {
        foreach ([true, false] as $ceo) {
            $html = $this->render($ceo);

            $this->assertStringContainsString("(ilNoStock(G.item_name) || ((stock.ready && stockFor(G.item_name))", $html, 'Stock / Incoming');
            $this->assertStringContainsString("st('nonexus', 4, '⚪ ' + this.ilNoStock(name), 'grey', this.ilNoStockTip(name))", $html, 'Next step');
            $qty = $this->between($html, "<span class=\"il-m-label\">Qty to order</span>", '</td>');
            $this->assertStringContainsString("x-text=\"ilNoStock(G.item_name) + ': counted with stock 0'\"", $qty);
            $this->assertMatchesRegularExpression('/:class="ilNoStock\(G\.item_name\) \? \'il-muted\' : \'\'"\s+:title="ilNoStock\(G\.item_name\) \? ilNoStockTip\(G\.item_name\)/', $html);
            $this->assertMatchesRegularExpression('/\.il-muted\{[^}]*opacity:/', $html);
            $list = $this->between($html, '<details class="il-unmatched il-not-in-nexus"', '</details>');
            $this->assertStringContainsString("x-text=\"num(stock.meta.nexus.not_in_nexus_count) + ' items Not in Nexus'\"", $list);
            $this->assertStringContainsString('<li x-text="name"></li>', $list);
        }
    }

    public function test_no_matches_at_all_says_not_synced_or_no_matches_and_the_not_in_nexus_tooltip_explains_nexus_boxes(): void
    {
        $html = $this->render(true);

        foreach (["if (S.not_in_nexus) return 'Not in Nexus';", "if (S.stock_unknown === 'not_synced') return 'Nexus not synced yet';",
                  "if (S.stock_unknown === 'no_matches') return 'No Nexus name matches';",
                  "' Nexus still shows boxes of it: they were stocked in before the history start, or a lot was never scanned at Stock In.'",
                  '(S && S.nexus_remaining != null)'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    public function test_B1_the_suppliers_tab_shows_not_in_nexus_and_mutes_doi_order_by_and_qty(): void
    {
        $html = $this->render(true, true);   // ang table na may suppliers (_table_suppliers → _agg_cells)
        $stock = $this->between($html, "<template x-if=\"col.id==='stock'\">", '</template>');
        $doi   = $this->between($html, "<template x-if=\"col.id==='doi'\">", "<template x-if=\"stockFor(row.item_name)\">");
        $qty   = $this->between($html, "<template x-if=\"col.id==='order_qty'\">", "<template x-if=\"col.id==='lifecycle'\">");

        $this->assertStringContainsString("x-text=\"ilNoStock(row.item_name) || ((stock.ready && stockFor(row.item_name)) ? num(stockFor(row.item_name).stock) : '—')\"", $stock, 'hindi "0" galing sa num(null)');
        $this->assertStringContainsString(':title="ilNoStock(row.item_name) ? ilNoStockTip(row.item_name) :', $stock);
        $this->assertStringContainsString(":class=\"ilNoStock(row.item_name) ? 'il-muted' : ''\"", $doi, 'DOI naka-mute');
        $this->assertStringContainsString(':title="ilNoStock(row.item_name) ? ilNoStockTip(row.item_name) :', $doi);
        $this->assertStringContainsString(":class=\"ilNoStock(row.item_name) ? 'il-muted' : ''\"", $qty, 'qty at order-by naka-mute');
        $this->assertStringContainsString(":title=\"ilNoStock(row.item_name) ? ilNoStockTip(row.item_name) : 'Huling araw", $qty, 'order-by tooltip');
        $this->assertStringContainsString("x-text=\"ilNoStock(row.item_name) + ': stock 0 ang gamit'\"", $qty);
        $this->assertMatchesRegularExpression('/\.il-muted\{[^}]*opacity:/', $html, 'ang CSS ay nasa layout na ito rin');
    }

    public function test_S_220_6_9_the_new_lines_wrap_at_phone_width(): void
    {
        $html = $this->render(true);

        $this->assertMatchesRegularExpression('/\.il-stock-src\{[^}]*flex-wrap:wrap[^}]*\}/', $html);
        $this->assertMatchesRegularExpression('/\.il-stock-src\{[^}]*overflow-wrap:anywhere[^}]*\}/', $html);
        $this->assertMatchesRegularExpression('/\.il-unmatched ul\{[^}]*max-height:[^}]*overflow:auto[^}]*\}/', $html);
    }

    public function test_the_old_layout_is_unchanged(): void
    {
        $this->assertStringNotContainsString('id="il-stock-src"', $this->render(true, true));
    }
}
