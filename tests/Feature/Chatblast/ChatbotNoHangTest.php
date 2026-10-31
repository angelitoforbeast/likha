<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * /chatbot/pages hindi nagha-hang: ang row ay binubuo ulit lang kapag nagbago ang ipinapakita nito (row-cache), ang
 * reload ay nag-iiwan ng lumang object ng page na hindi nagbago, at ang sukat ng fade ay basa muna lahat, saka sulat
 * (clamp). Ang mga bahaging ito ng page ay pinapatakbo sa Node; ang oras sa browser ay nasa tests/perf.
 */
class ChatbotNoHangTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
        $this->actingAs($this->user());
    }

    /** Ang bahagi ng page sa pagitan ng dalawang marker, pinapatakbo sa Node kasama ang $script. */
    private function node(string $marker, string $script): mixed
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// ' . $marker . ':start(.*?)// ' . $marker . ':end~s', $html, $m), $marker . ' is in the page');
        $file = tempnam(sys_get_temp_dir(), 'nohang') . '.mjs';
        file_put_contents($file, html_entity_decode($m[1], ENT_QUOTES) . "\n" . $script);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_209_1_and_5_a_reload_keeps_unchanged_rows_and_never_keeps_a_row_whose_values_changed(): void
    {
        $out = $this->node('row-cache', <<<'JS'
            const row = (page, main, extra = {}) => ({page, active: true, cells: {main_flow: main, price: '499'}, check: null, ...extra});
            const old = [row('A', 'Hi A'), row('B', 'Hi B'), row('C', 'Hi C')];
            // Save of B (or its Undo): the server sends B with the new value; A and C are the same; D is new, C is gone.
            const fresh = [row('A', 'Hi A'), row('B', 'Saved B'), row('D', 'Hi D')];
            const merged = RowCache.merge(old, fresh);
            console.log(JSON.stringify({
                pages: merged.map(r => r.page),
                keptA: merged[0] === old[0],
                newB: merged[1] === fresh[1] && merged[1].cells.main_flow === 'Saved B',
                newD: merged[2] === fresh[2],
                // Same data, keys in another order: a redraw too many, never one too few.
                reordered: RowCache.merge([{page: 'A', cells: {a: 1, b: 2}}], [{page: 'A', cells: {b: 2, a: 1}}])[0].cells.a === 1,
            }));
            JS);
        $this->assertSame(['A', 'B', 'D'], $out['pages']);
        $this->assertTrue($out['keptA'], 'an unchanged page keeps its row object (its buttons point at it)');
        $this->assertTrue($out['newB'], 'a page whose value changed takes the new object');
        $this->assertTrue($out['newD']);
        $this->assertTrue($out['reordered']);
    }

    public function test_S_209_1_and_5_the_row_signature_changes_with_everything_the_row_shows(): void
    {
        $out = $this->node('row-cache', <<<'JS'
            const r = {page: 'A', active: true, cells: {main_flow: 'Hi'}};
            const state = {cols: 'flow,main_flow', marks: [], pending: [], error: null, check: null, checking: false, ag: [false, 0], frozen: true};
            const base = RowCache.signature(r, state);
            const changed = {};
            const vary = (name, fn) => { const r2 = structuredClone(r), s2 = structuredClone(state); fn(r2, s2); changed[name] = RowCache.signature(r2, s2) !== base; };
            vary('value saved', r2 => { r2.cells.main_flow = 'Saved'; });
            vary('value cleared', r2 => { delete r2.cells.main_flow; });
            vary('switched off', r2 => { r2.active = false; });
            vary('mark Saving', (r2, s2) => { s2.marks = [['main_flow', 'Saving…']]; });
            vary('mark failed', (r2, s2) => { s2.marks = [['main_flow', '!']]; });
            vary('pasted, not saved', (r2, s2) => { s2.pending = [['main_flow', 'Pasted']]; });
            vary('error', (r2, s2) => { s2.error = 'Not changed.'; });
            vary('check result', (r2, s2) => { s2.check = {status: 'ok'}; });
            vary('checking', (r2, s2) => { s2.checking = true; });
            vary('ad groups shown', (r2, s2) => { s2.ag = [true, 0]; });
            vary('ad groups loaded', (r2, s2) => { s2.ag = [false, 1]; });
            vary('column added', (r2, s2) => { s2.cols += ',promo'; });
            vary('wide screen', (r2, s2) => { s2.frozen = false; });
            console.log(JSON.stringify({changed, same: RowCache.signature(structuredClone(r), structuredClone(state)) === base}));
            JS);
        $this->assertTrue($out['same'], 'the same row and state give the same signature (the row is kept)');
        foreach ($out['changed'] as $what => $differs) $this->assertTrue($differs, $what . ' redraws the row');
    }

    public function test_S_209_4_the_fade_measures_every_cell_before_it_writes_any(): void
    {
        $out = $this->node('clamp', <<<'JS'
            const log = [];
            const cell = (name, scrollHeight, clientHeight, scrollTop = 0) => {
                const classes = new Set();
                return {name, get scrollHeight() { log.push('read'); return scrollHeight; }, get clientHeight() { log.push('read'); return clientHeight; },
                    get scrollTop() { log.push('read'); return scrollTop; }, set scrollTop(v) { log.push('write'); scrollTop = v; },
                    classList: {toggle(c, on) { log.push('write'); if (on) classes.add(c); else classes.delete(c); }}, classes, top: () => scrollTop};
            };
            // Long (5 lines in a 3-line box), short, and one scrolled down while its row was open.
            const cells = [cell('long', 100, 60), cell('short', 40, 60), cell('scrolled', 100, 60, 30)];
            clampPass(cells);
            const firstWrite = log.indexOf('write'), lastRead = log.lastIndexOf('read');
            const folds = [];
            const box = (name, over) => ({name, get scrollHeight() { folds.push('read'); return over ? 100 : 60; }, get clientHeight() { folds.push('read'); return 60; }});
            const more = foldPass([box('a', true), box('b', false), box('c', true)], b => folds.push('write ' + b.name));
            console.log(JSON.stringify({
                readsFirst: lastRead < firstWrite,
                faded: cells.filter(c => c.classes.has('cb-clamped')).map(c => c.name),
                scrolledBack: cells[2].top(),
                foldOrder: folds,
            }));
            JS);
        $this->assertTrue($out['readsFirst'], 'no read after a write (one layout, not one per cell)');
        $this->assertSame(['long', 'scrolled'], $out['faded'], 'the fade on long texts only');
        $this->assertSame(0, $out['scrolledBack'], 'a cell scrolled while open is back at the top');
        $this->assertSame(['read', 'read', 'read', 'read', 'read', 'read', 'write a', 'write c'], $out['foldOrder'], 'Show all: every box measured, then the buttons added');
    }
}
