<?php

namespace Tests\Feature\Botcake;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** botcake:psid-page-id-backfill: pinupunan ang page_id ng lumang botcake_psids mula sa pangalan ng page sa page settings. */
class BotcakePsidPageIdBackfillTest extends BotcakeApiTestCase
{
    private function row(string $page, string $psid, ?string $pageId = null): int
    {
        return DB::table('botcake_psids')->insertGetId([
            'page' => $page, 'fb_name' => 'Customer ' . $psid, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
            'page_id' => $pageId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pageId(string $page, string $id): void
    {
        app(PageSettings::class)->set($page, 'botcake', 'page_id', $id);
    }

    /** @return array<string, string|null> psid => page_id */
    private function ids(): array
    {
        return DB::table('botcake_psids')->orderBy('id')->pluck('page_id', 'psid')->all();
    }

    private function backfill(array $options = []): string
    {
        Artisan::call('botcake:psid-page-id-backfill', $options + ['--sleep' => 0]);

        return Artisan::output();
    }

    public function test_S_233_2_1_rows_of_three_named_pages_are_all_filled(): void
    {
        $this->pageId('Page Uno', '1001');
        $this->pageId('Page Dos', '1002');
        $this->pageId('Page Tres', '1003');
        $this->row('Page Uno', 'p1');
        $this->row('Page Dos', 'p2');
        $this->row('Page Tres', 'p3');
        $this->row('Page Uno', 'p4');
        Log::spy();

        $out = $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => '1002', 'p3' => '1003', 'p4' => '1001'], $this->ids());
        $this->assertStringContainsString('Filled: 4', $out);
        $this->assertStringContainsString('No matching page: 0', $out);
        $this->assertStringContainsString('Left without page id: 0', $out);
        // Bilang lang ang nasa log: walang pangalan ng customer o PSID.
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'psid-page-id-backfill')
            && $ctx === ['filled' => 4, 'no_page' => 0, 'ambiguous' => 0, 'left' => 0, 'chunks' => 1])->once();
    }

    public function test_S_233_2_2_a_name_without_one_page_id_stays_null_and_is_reported(): void
    {
        $this->pageId('Page Uno', '1001');
        // Ang "Shop" ay naging id 2001, saka 2002 (inalis ang luma): dalawang id sa iisang pangalan, hindi pinupunan.
        $this->pageId('Shop', '2001');
        $this->pageId('Shop', '2002');
        $this->row('Page Uno', 'p1');
        $this->row('Lost Page', 'p2');
        $this->row('Lost Page', 'p3');
        $this->row('Shop', 'p4');

        $out = $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => null, 'p3' => null, 'p4' => null], $this->ids());
        $this->assertStringContainsString('No matching page: 2', $out);
        $this->assertStringContainsString('More than one page id: 1', $out);
        $this->assertStringContainsString('Left without page id: 3', $out);
        $this->assertStringContainsString('Lost Page: 2', $out);
        $this->assertStringContainsString('Shop: 1 (more than one page id)', $out);
        $this->assertStringNotContainsString('p2', $out, 'walang PSID sa output');
    }

    public function test_S_233_2_3_a_second_run_changes_nothing(): void
    {
        $this->pageId('Page Uno', '1001');
        $this->row('Page Uno', 'p1');
        $this->row('Page Uno', 'p2', '9999'); // may page_id na: hindi ginagalaw
        $this->backfill();
        $before = DB::table('botcake_psids')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $out = $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => '9999'], $this->ids());
        $this->assertSame($before, DB::table('botcake_psids')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertStringContainsString('Filled: 0', $out);
    }

    public function test_S_233_2_4_a_run_stopped_part_way_finishes_on_the_next_run(): void
    {
        $this->pageId('Page Uno', '1001');
        foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $psid) $this->row('Page Uno', $psid);

        $this->backfill(['--chunk' => 2, '--max-chunks' => 1]);
        $this->assertSame(['p1' => '1001', 'p2' => '1001', 'p3' => null, 'p4' => null, 'p5' => null], $this->ids());

        $out = $this->backfill(['--chunk' => 2]);

        $this->assertSame(array_fill_keys(['p1', 'p2', 'p3', 'p4', 'p5'], '1001'), $this->ids());
        $this->assertStringContainsString('Filled: 3', $out);
    }

    public function test_S_233_2_5_names_differing_in_case_or_spacing_match(): void
    {
        $this->pageId('Page Uno', '1001');
        // Pinalitan ang pangalan: ang lumang "Old Name" (history, hindi na active) ay page id 3001 pa rin.
        $this->pageId('Old Name', '3001');
        app(PageSettings::class)->deactivate(PageSetting::where('page_name', 'Old Name')->where('active', true)->sole());
        $this->pageId('New Name', '3001');
        $this->row(' page   UNO ', 'p1');
        $this->row('PAGE UNO', 'p2');
        $this->row('old name', 'p3');

        $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => '1001', 'p3' => '3001'], $this->ids());
    }
}
