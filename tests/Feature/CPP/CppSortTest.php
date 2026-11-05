<?php

namespace Tests\Feature\CPP;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * /ads_manager/cpp: pag-sort ng tatlong table sa pag-click ng header. Ang tuntunin at ang pag-wire ay nasa
 * public/js/cpp-sort.js, pinapatakbo sa Node na may maliit na DOM (tests/js/dom-stub.cjs) sa table na gaya ng iginuguhit
 * ng page; ang page test ay tumitingin na isinasama ng page ang script at ikinakabit sa bawat table pagkaguhit.
 */
class CppSortTest extends ChatblastTestCase
{
    private const DAY = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
            if (!Schema::hasColumn('macro_output', 'botcake_ad_id')) $t->string('botcake_ad_id', 64)->nullable();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_id')->nullable();
            $t->string('ad_set_id')->nullable();
            $t->string('ad_id')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->bigInteger('messaging_conversations_started')->nullable();
            $t->bigInteger('impressions')->nullable();
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        $this->actingAs($this->user());
    }

    /** Pinapatakbo ang $script sa Node; may `table()`, `names()`, `aria()`, `click()` at ang by-page na table na `byPage()`. */
    private function js(string $script): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $js = "const STUB = require(" . json_encode(base_path('tests/js/dom-stub.cjs')) . ");\n"
            // Plain na script (module ang .js sa repo na ito), kaya pinapatakbo gaya ng sa browser, hindi require.
            . "require('vm').runInThisContext(require('fs').readFileSync(" . json_encode(public_path('js/cpp-sort.js')) . ", 'utf8'));\n"
            . <<<'JS'
            // Gaya ng iginuguhit ng page: thead na may isang row ng th, tbody na may mga row; [text, data-sort] para sa petsa.
            function table(heads, rows) {
              const t = document.createElement('table'), head = document.createElement('thead'), hr = document.createElement('tr'), body = document.createElement('tbody');
              heads.forEach(h => { const th = document.createElement('th'); th.textContent = h; hr.append(th); });
              head.append(hr); t.append(head, body);
              rows.forEach(r => {
                const tr = document.createElement('tr');
                if (r.cls) tr.setAttribute('class', r.cls);
                if (r.fixed) tr.setAttribute('data-sort-fixed', '');
                r.cells.forEach(v => { const td = document.createElement('td'); if (Array.isArray(v)) { td.textContent = v[0]; td.setAttribute('data-sort', v[1]); } else td.textContent = v; tr.append(td); });
                body.append(tr);
              });
              return t;
            }
            const names = t => t.querySelector('tbody').children.map(tr => tr.children[0].textContent);
            const aria = t => t.querySelectorAll('th').map(h => h.getAttribute('aria-sort'));
            const click = (t, label) => STUB.find(t, 'th', label)[0].click();
            // Ang by-page na table: may ad group rows ang "aircon cover" (sariling order, sariling CPP), at TOTAL sa dulo.
            const byPage = () => table(['Page Name', 'Amount Spent', 'CPP', 'TCPR'], [
              {cls: 'cpp-page-row', cells: ['Mobile Tripod', '₱900.00', '₱900.00', '9.00%']},
              {cls: 'cpp-page-row', cells: ['aircon cover', '₱1,200.00', '₱1,200.00', '12.00%']},
              {cls: 'cpp-ag-row text-xs', cells: ['Ad group: Z', '₱5.00', '₱5.00', '—']},
              {cls: 'cpp-ag-row text-xs', cells: ['Ad group: A', '₱3,000.00', '₱3,000.00', '50.00%']},
              {cls: 'cpp-page-row', cells: ['Zest', '₱10.00', '—', '—']},
              {cls: 'cpp-page-row', cells: ['Bag', '₱50.00', '₱50.00', '3.50%']},
              {fixed: true, cells: ['TOTAL (4 pages)', '₱2,160.00', '₱720.00', '8.00%']},
            ]);
            const out = {};
            JS
            . "\n" . $script . "\nconsole.log(JSON.stringify(out));\n";
        $file = tempnam(sys_get_temp_dir(), 'cppsort') . '.cjs';
        file_put_contents($file, $js);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function cppPage(): string
    {
        return $this->get('/ads_manager/cpp?start=' . self::DAY . '&end=' . self::DAY)->assertOk()->getContent();
    }

    public function test_S_230_1_1_cpp_sorts_highest_first_then_lowest_first_then_back_to_the_original_order(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            out.start = [names(t), aria(t)];
            click(t, 'CPP'); out.first = [names(t), aria(t)];
            click(t, 'CPP'); out.second = [names(t), aria(t)];
            click(t, 'CPP'); out.third = [names(t), aria(t)];
            JS);

        $original = ['Mobile Tripod', 'aircon cover', 'Ad group: Z', 'Ad group: A', 'Zest', 'Bag', 'TOTAL (4 pages)'];
        $none = [null, null, null, null];
        $this->assertSame([$original, $none], $out['start'], 'nothing moves until a header is clicked');
        $this->assertSame([['aircon cover', 'Ad group: Z', 'Ad group: A', 'Mobile Tripod', 'Bag', 'Zest', 'TOTAL (4 pages)'],
            [null, null, 'descending', null]], $out['first'], 'highest CPP first, ▼ (descending) on CPP only');
        $this->assertSame([['Bag', 'Mobile Tripod', 'aircon cover', 'Ad group: Z', 'Ad group: A', 'Zest', 'TOTAL (4 pages)'],
            [null, null, 'ascending', null]], $out['second'], 'lowest first, ▲ (ascending)');
        $this->assertSame([$original, $none], $out['third'], 'third click: the original order and no arrow');
    }

    public function test_S_230_1_2_page_name_sorts_a_to_z_then_z_to_a(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            click(t, 'Page Name'); out.first = [names(t), aria(t)[0]];
            click(t, 'Page Name'); out.second = [names(t), aria(t)[0]];
            JS);

        $this->assertSame([['aircon cover', 'Ad group: Z', 'Ad group: A', 'Bag', 'Mobile Tripod', 'Zest', 'TOTAL (4 pages)'], 'ascending'], $out['first'],
            'A→Z, upper and lower case together');
        $this->assertSame([['Zest', 'Mobile Tripod', 'Bag', 'aircon cover', 'Ad group: Z', 'Ad group: A', 'TOTAL (4 pages)'], 'descending'], $out['second']);
    }

    public function test_S_230_1_3_date_sorts_newest_first_then_oldest_first_in_both_by_date_tables(): void
    {
        $out = $this->js(<<<'JS'
            // Ang text ay "Nov 5, 2026" (fmtISO), kaya ang petsa ay nasa data-sort; sa text, "Dec" ay mauuna sa "Nov".
            const rows = [['Nov 5, 2026', '2026-11-05'], ['Dec 1, 2025', '2025-12-01'], ['Oct 30, 2026', '2026-10-30']];
            const byDate = table(['Date', 'Amount Spent', 'Orders', 'CPP', 'CPI', 'CPM', 'TCPR'],
              rows.map(d => ({cells: [d, '₱1.00', '1', '₱1.00', '₱1.00', '₱1.00', '1.00%']})));
            const withItems = table(['Date', 'Amount Spent', 'Orders', 'CPP', 'CPM', 'TCPR', 'Item Names', 'CODs', 'PROCEED'],
              rows.map(d => ({cells: [d, '₱1.00', '1', '₱1.00', '₱1.00', '1.00%', 'Tripod', '499', '1']})).concat([{fixed: true, cells: ['TOTAL', '₱3.00', '3', '₱1.00', '₱1.00', '1.00%', '']}]));
            CppSort.attach(byDate, {}, 'date'); CppSort.attach(withItems, {}, 'single');
            click(byDate, 'Date'); click(withItems, 'Date'); out.first = [names(byDate), names(withItems), aria(byDate)[0]];
            click(byDate, 'Date'); click(withItems, 'Date'); out.second = [names(byDate), names(withItems), aria(byDate)[0]];
            JS);

        $this->assertSame([['Nov 5, 2026', 'Oct 30, 2026', 'Dec 1, 2025'], ['Nov 5, 2026', 'Oct 30, 2026', 'Dec 1, 2025', 'TOTAL'], 'descending'], $out['first'], 'newest first');
        $this->assertSame([['Dec 1, 2025', 'Oct 30, 2026', 'Nov 5, 2026'], ['Dec 1, 2025', 'Oct 30, 2026', 'Nov 5, 2026', 'TOTAL'], 'ascending'], $out['second'], 'oldest first');
    }

    public function test_S_230_1_4_peso_comma_and_percent_values_sort_as_numbers_not_text(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            click(t, 'Amount Spent'); out.spent = names(t).filter(n => !n.startsWith('Ad group'));
            click(t, 'TCPR'); out.tcpr = names(t).filter(n => !n.startsWith('Ad group'));
            out.keys = ['₱1,200.00', '₱900', '12.00%', '9%', '1,234,567.5', '₱499, ₱599', 'Tripod'].map(v => CppSort.key(v, null));
            JS);

        $this->assertSame(['aircon cover', 'Mobile Tripod', 'Bag', 'Zest', 'TOTAL (4 pages)'], $out['spent'], '₱1,200.00 above ₱900.00 (as text "9" would win)');
        $this->assertSame(['aircon cover', 'Mobile Tripod', 'Bag', 'Zest', 'TOTAL (4 pages)'], $out['tcpr'], '12.00% above 9.00%');
        $this->assertSame([1200, 900, 12, 9, 1234567.5, '₱499, ₱599', 'Tripod'], $out['keys'], 'a list of prices stays text');
    }

    public function test_S_230_1_5_empty_and_dash_cells_are_last_in_both_directions(): void
    {
        $out = $this->js(<<<'JS'
            const t = table(['Page Name', 'Orders'], [
              {cells: ['A', '—']}, {cells: ['B', '5']}, {cells: ['C', '']}, {cells: ['D', '12']},
            ]);
            CppSort.attach(t, {}, 'page');
            click(t, 'Orders'); out.desc = names(t);
            click(t, 'Orders'); out.asc = names(t);
            JS);

        $this->assertSame(['D', 'B', 'A', 'C'], $out['desc']);
        $this->assertSame(['B', 'D', 'A', 'C'], $out['asc'], 'still last, in their own order');
    }

    public function test_S_230_2_1_the_total_row_stays_last_after_any_sort(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            out.last = [];
            ['Page Name', 'Amount Spent', 'CPP', 'TCPR'].forEach(h => [1, 2, 3].forEach(() => { click(t, h); out.last.push(names(t).slice(-1)[0]); }));
            JS);

        $this->assertSame(array_fill(0, 12, 'TOTAL (4 pages)'), $out['last']);
    }

    public function test_S_230_2_2_ad_group_rows_stay_under_their_page_in_their_own_order(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            const under = () => { const n = names(t), i = n.indexOf('aircon cover'); return n.slice(i, i + 3); };
            out.under = [];
            ['Page Name', 'Amount Spent', 'CPP', 'TCPR'].forEach(h => [1, 2].forEach(() => { click(t, h); out.under.push(under()); }));
            JS);

        $this->assertSame(array_fill(0, 8, ['aircon cover', 'Ad group: Z', 'Ad group: A']), $out['under'],
            'the group rows follow their page, Z before A as drawn, whatever their own CPP');
    }

    public function test_S_230_2_3_a_re_render_keeps_the_chosen_sort(): void
    {
        $out = $this->js(<<<'JS'
            // Iisang states para sa buong pagbisita; ang re-render ay bagong table (bagong innerHTML) na ikinakabit ulit.
            const states = {};
            const first = byPage(); CppSort.attach(first, states, 'page');
            click(first, 'CPP');
            const redrawn = byPage(); CppSort.attach(redrawn, states, 'page');
            out.redrawn = [names(redrawn), aria(redrawn)];
            const other = table(['Date'], [{cells: [['Oct 1, 2026', '2026-10-01']]}, {cells: [['Oct 2, 2026', '2026-10-02']]}]);
            CppSort.attach(other, states, 'date');
            out.other = [names(other), aria(other)];
            JS);

        $this->assertSame([['aircon cover', 'Ad group: Z', 'Ad group: A', 'Mobile Tripod', 'Bag', 'Zest', 'TOTAL (4 pages)'],
            [null, null, 'descending', null]], $out['redrawn'], 'the redrawn table comes sorted by CPP, ▼');
        $this->assertSame([['Oct 1, 2026', 'Oct 2, 2026'], [null]], $out['other'], 'each table keeps its own sort');

        $html = $this->cppPage();
        $this->assertStringContainsString('const cppSorts = {};', $html, 'one sort state per visit, never saved');
        $this->assertSame(1, substr_count($html, "CppSort.attach(document.getElementById('summaryOfAdsTable'), cppSorts, 'page');"));
        $this->assertSame(1, substr_count($html, "CppSort.attach(document.getElementById('cppDateTable'), cppSorts, 'date');"));
        $this->assertSame(1, substr_count($html, "CppSort.attach(document.getElementById('singlePageTable'), cppSorts, 'single');"));
        $this->assertMatchesRegularExpression("/tableRight\\.innerHTML = summaryHtml \\+ dateHtml;\\s*CppSort\\.attach\\(document\\.getElementById\\('summaryOfAdsTable'\\)/", $html,
            'attached right after the tables are drawn');
        $this->assertMatchesRegularExpression("/tableRight\\.innerHTML = html;\\s*CppSort\\.attach\\(document\\.getElementById\\('singlePageTable'\\)/", $html);
    }

    public function test_S_230_2_4_copy_table_copies_the_rows_in_the_shown_order(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            click(t, 'CPP');
            // Ang binabasa ng Copy Table: ang mga tr ng table sa DOM order, at ang text ng bawat cell.
            out.copied = t.querySelectorAll('tr').map(tr => tr.children.map(c => c.textContent).join('\t'));
            JS);

        $this->assertSame([
            "Page Name\tAmount Spent\tCPP\tTCPR",
            "aircon cover\t₱1,200.00\t₱1,200.00\t12.00%",
            "Ad group: Z\t₱5.00\t₱5.00\t—",
            "Ad group: A\t₱3,000.00\t₱3,000.00\t50.00%",
            "Mobile Tripod\t₱900.00\t₱900.00\t9.00%",
            "Bag\t₱50.00\t₱50.00\t3.50%",
            "Zest\t₱10.00\t—\t—",
            "TOTAL (4 pages)\t₱2,160.00\t₱720.00\t8.00%",
        ], $out['copied'], 'the rows move in the DOM, and the header text holds no arrow');

        $html = $this->cppPage();
        $this->assertSame(2, substr_count($html, "Array.from(table.querySelectorAll('tr'))"), 'both copy functions read the live rows in DOM order');
        $this->assertStringContainsString('th[aria-sort="descending"]::after { content: " ▼"; }', $html, 'the arrow is CSS, outside the copied text');
        $this->assertStringContainsString('th[aria-sort="ascending"]::after { content: " ▲"; }', $html);
    }

    public function test_S_230_3_1_enter_or_space_on_a_header_sorts_and_aria_sort_is_on_that_header_only(): void
    {
        $out = $this->js(<<<'JS'
            const t = byPage(); CppSort.attach(t, {}, 'page');
            const th = h => STUB.find(t, 'th', h)[0];
            out.reach = t.querySelectorAll('th').map(h => [h.getAttribute('tabindex'), h.style.cursor]);
            th('CPP').dispatch('keydown', {key: 'a'}); out.other = aria(t);
            th('CPP').dispatch('keydown', {key: 'Enter'}); out.enter = [names(t)[0], aria(t)];
            th('Page Name').dispatch('keydown', {key: ' '}); out.space = [names(t)[0], aria(t)];
            JS);

        $this->assertSame(array_fill(0, 4, ['0', 'pointer']), $out['reach'], 'each header is in the Tab order and shows a pointer');
        $this->assertSame([null, null, null, null], $out['other'], 'other keys do nothing');
        $this->assertSame(['aircon cover', [null, null, 'descending', null]], $out['enter']);
        $this->assertSame(['aircon cover', ['ascending', null, null, null]], $out['space'], 'Space sorts too; CPP loses its aria-sort');
    }

    public function test_S_230_the_page_loads_the_sort_script_and_marks_the_rows_that_stay_put(): void
    {
        $html = $this->cppPage();

        $this->assertMatchesRegularExpression('#<script src="[^"]*/js/cpp-sort\.js\?v=[0-9a-f]{10}"></script>#', $html);
        $this->assertStringContainsString('<table id="cppDateTable"', $html);
        $this->assertSame(2, substr_count($html, 'data-sort-fixed'), 'both TOTAL rows stay at the bottom');
        $this->assertSame(2, substr_count($html, 'data-sort="${date}"'), 'both date columns sort by the ISO date');
    }
}
