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
        foreach (["'stock-in '", "' + returned '", "' − shipped '", "'Nexus on shelf '", "' (as of '", "', difference '",
                  "'Nexus also counts the stock from before the start, so a difference is expected'", "' (old)'"] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Malalaking numero: lahat ng numero ay dumadaan sa num() (toLocaleString).
        $this->assertMatchesRegularExpression('/stockBreakdown\(name\)\{[^}]*this\.num\(/s', $html);
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
