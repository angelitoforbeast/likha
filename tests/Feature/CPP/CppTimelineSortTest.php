<?php

namespace Tests\Feature\CPP;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * /ads_manager/cpp/timeline: pag-sort ng table sa snapshot popup sa pag-click ng header. Ang tuntunin ay nasa
 * public/js/cpp-sort.js; ang data ng popup (Alpine x-for) ang sinosort, hindi ang DOM. Pinapatakbo sa Node ang
 * cppTimeline() ng page mismo, may pekeng fetch na nagbabalik ng snapshot, gaya ng tests/Feature/CPP/CppSortTest.php.
 */
class CppTimelineSortTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
    }

    private function timelinePage(): string
    {
        return $this->get('/ads_manager/cpp/timeline?start=2026-11-05&end=2026-11-05')->assertOk()->getContent();
    }

    /** Pinapatakbo ang $script (async) sa Node na may `c` (ang cppTimeline ng page), `open()`, `names()` at `aria()`. */
    private function js(string $script): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $this->assertSame(1, preg_match('#<script>\s*(function cppTimeline\(\)[\s\S]*?)</script>#', $this->timelinePage(), $m));
        $js = "require('vm').runInThisContext(require('fs').readFileSync(" . json_encode(public_path('js/cpp-sort.js')) . ", 'utf8'));\n"
            . "require('vm').runInThisContext(" . json_encode($m[1]) . ");\n"
            . <<<'JS'
            // Gaya ng ibinabalik ng /ads_manager/cpp/timeline/detail: decimal bilang string, null kapag walang CPP.
            const rows = () => [
              {page_name: 'Mobile Tripod', item_names: 'Tripod', amount_spent: '900.00', orders: 1, proceed_orders: 1, cpp: '900.00', cpi: '9.00', cpm: '90.00', tcpr_pct: '9.0'},
              {page_name: 'aircon cover', item_names: '', amount_spent: '1200.00', orders: 1, proceed_orders: 0, cpp: '1200.00', cpi: '12.00', cpm: '120.00', tcpr_pct: '12.0'},
              {page_name: 'Zest', item_names: 'Zest Bag', amount_spent: '10.00', orders: 0, proceed_orders: 0, cpp: null, cpi: '1.00', cpm: '10.00', tcpr_pct: null},
              {page_name: 'Bag', item_names: 'Bag', amount_spent: '50.00', orders: 1, proceed_orders: 1, cpp: '50.00', cpi: '5.00', cpm: '50.00', tcpr_pct: '3.5'},
            ];
            globalThis.fetch = async () => ({ok: true, json: async () => ({ok: true, rows: rows(), totals: {amount_spent: 2160, orders: 3, proceed_orders: 2, cpp: 720}, zero_spent_count: 0})});
            const c = cppTimeline();
            const open = (bucket) => c.openDetail('2026-11-05', bucket || '7PM');
            const names = () => c.modalVisibleRows().map(r => r.page_name);
            const aria = () => [0, 1, 2, 3, 4, 5, 6, 7, 8].map(i => c.modalAria(i));
            const out = {};
            JS
            . "\n(async () => {\n" . $script . "\n})().then(() => console.log(JSON.stringify(out)));\n";
        $file = tempnam(sys_get_temp_dir(), 'cpptl') . '.cjs';
        file_put_contents($file, $js);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_S_236_1_1_a_numeric_column_sorts_highest_first_then_reversed_then_back_to_the_original_order(): void
    {
        $out = $this->js(<<<'JS'
            await open();
            out.start = [names(), aria()];
            c.sortModal(5); out.first = [names(), aria()];
            c.sortModal(5); out.second = [names(), aria()];
            c.sortModal(5); out.third = [names(), aria()];
            JS);

        $original = ['Mobile Tripod', 'aircon cover', 'Zest', 'Bag'];
        $none = array_fill(0, 9, null);
        $cpp = fn ($dir) => [null, null, null, null, null, $dir, null, null, null];
        $this->assertSame([$original, $none], $out['start'], 'nothing moves until a header is clicked');
        $this->assertSame([['aircon cover', 'Mobile Tripod', 'Bag', 'Zest'], $cpp('descending')], $out['first'],
            '1,200 above 900 (as numbers), the page without a CPP last, ▼ on CPP only');
        $this->assertSame([['Bag', 'Mobile Tripod', 'aircon cover', 'Zest'], $cpp('ascending')], $out['second'], 'reversed, the empty one still last');
        $this->assertSame([$original, $none], $out['third'], 'third click: the original order and no arrow');
    }

    public function test_S_236_1_2_a_text_column_sorts_a_to_z(): void
    {
        $out = $this->js(<<<'JS'
            await open();
            c.sortModal(0); out.first = [names(), aria()[0]];
            JS);

        $this->assertSame([['aircon cover', 'Bag', 'Mobile Tripod', 'Zest'], 'ascending'], $out['first'], 'A→Z, upper and lower case together');
    }

    public function test_S_236_1_3_the_total_row_stays_last_and_empty_cells_go_last(): void
    {
        $out = $this->js(<<<'JS'
            await open();
            c.sortModal(1); out.asc = names();
            c.sortModal(1); out.desc = names();
            JS);

        $this->assertSame(['Bag', 'Mobile Tripod', 'Zest', 'aircon cover'], $out['asc'], 'the page without item names is last');
        $this->assertSame(['Zest', 'Mobile Tripod', 'Bag', 'aircon cover'], $out['desc'], 'still last when reversed');

        // Ang TOTAL ay nasa tfoot, labas sa mga row na sinosort (ang x-for sa tbody), kaya laging nasa ibaba.
        $html = $this->timelinePage();
        $this->assertMatchesRegularExpression('#<tbody>\s*<template x-for="r in modalVisibleRows\(\)" :key="r.page_name">[\s\S]*?</tbody>\s*<tfoot>\s*<tr>\s*<td>TOTAL</td>#', $html);
    }

    public function test_S_236_1_4_opening_a_new_snapshot_starts_unsorted(): void
    {
        $out = $this->js(<<<'JS'
            await open('7PM');
            c.sortModal(5); out.sorted = names();
            c.closeDetail();
            await open('8PM');
            out.reopened = [names(), aria()];
            JS);

        $this->assertSame(['aircon cover', 'Mobile Tripod', 'Bag', 'Zest'], $out['sorted']);
        $this->assertSame([['Mobile Tripod', 'aircon cover', 'Zest', 'Bag'], array_fill(0, 9, null)], $out['reopened']);
    }

    public function test_S_236_the_popup_headers_sort_by_click_enter_or_space_and_show_the_arrow(): void
    {
        $html = $this->timelinePage();

        $this->assertMatchesRegularExpression('#<script src="[^"]*/js/cpp-sort\.js\?v=[0-9a-f]{10}"></script>#', $html);
        $this->assertStringContainsString('th[aria-sort="descending"]::after { content: " ▼"; }', $html);
        $this->assertStringContainsString('th[aria-sort="ascending"]::after { content: " ▲"; }', $html);
        foreach (range(0, 8) as $i) {
            $this->assertSame(1, substr_count($html, "tabindex=\"0\" :aria-sort=\"modalAria($i)\" @click=\"sortModal($i)\" @keydown.enter.prevent=\"sortModal($i)\" @keydown.space.prevent=\"sortModal($i)\""),
                "header $i is in the Tab order and sorts by click, Enter or Space");
        }
    }
}
