<?php

namespace Tests\Feature\Chatblast;

/**
 * /chatbot/pages: ang mga naka-freeze na column ay may tiyak na lapad (min = max) at ang left ng bawat isa ay ang kabuuan
 * ng lapad ng mga nauna; at ang pagpapangkat ng page at ng mga ad group nito ay kita sa guhit at usog. Ang sukat mismo
 * ay sinusuri sa browser; dito ang mga rule ng CSS.
 */
class PinnedColumnsTest extends ChatblastTestCase
{
    private function html(): string
    {
        $this->actingAs($this->user());

        return $this->get('/chatbot/pages')->assertOk()->getContent();
    }

    public function test_S_225_2_1_each_pinned_column_has_one_fixed_width(): void
    {
        $html = $this->html();

        foreach ([1, 2, 3, 4] as $n) {
            $this->assertStringContainsString("#cb-table .stick-$n { width: var(--cb-w$n); min-width: var(--cb-w$n); max-width: var(--cb-w$n); }", $html);
        }
        $this->assertStringContainsString('box-sizing: border-box; overflow-wrap: anywhere;', $html, 'long names wrap inside the column');
        $this->assertDoesNotMatchRegularExpression("/class: 'stick-[1-4] (w-|min-w-)/", $html, 'the header cells carry no width of their own');
    }

    public function test_S_225_2_2_each_left_offset_is_the_sum_of_the_widths_before_it(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('#cb-table .stick-2 { position: sticky; left: var(--cb-w1);', $html);
        $this->assertStringContainsString('#cb-table .stick-3 { position: sticky; left: calc(var(--cb-w1) + var(--cb-w2));', $html);
        $this->assertStringContainsString('#cb-table .stick-4 { position: sticky; left: calc(var(--cb-w1) + var(--cb-w2) + var(--cb-w3));', $html);
        $this->assertDoesNotMatchRegularExpression('/left: (12|18|28)rem/', $html, 'no hard-coded offsets left');
    }

    public function test_S_225_4_1_pages_ad_groups_and_their_rows_read_as_one_block(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('#cb-table tbody tr:not(.ag-sub):not(:first-child) > td { border-top: 2px solid #94a3b8; }', $html, 'thick line between pages');
        $this->assertStringContainsString('#cb-table tr.ag-sub[data-ag-group] > td { border-top: 1px solid #cbd5e1; }', $html, 'thin lighter line between ad groups');
        $this->assertStringContainsString('#cb-table tr.ag-sub > td.stick-1, #cb-table tr.ag-sub .ag-pin { padding-left: 1.75rem;', $html, 'sub-rows indented');
    }
}
