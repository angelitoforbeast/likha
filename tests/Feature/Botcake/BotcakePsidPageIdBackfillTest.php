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
    private function row(string $page, string $psid, ?string $pageId = null, ?\DateTimeInterface $createdAt = null): int
    {
        return DB::table('botcake_psids')->insertGetId([
            'page' => $page, 'fb_name' => 'Customer ' . $psid, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
            'page_id' => $pageId, 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }

    /** Pinalitan ang pangalan: ang botcake.page_id ng $from ay hindi na active (history), at nasa $to na. */
    private function rename(string $from, string $to): void
    {
        $id = (string) PageSetting::where('page_name', $from)->where('key', 'page_id')->where('active', true)->sole()->plainValue();
        app(PageSettings::class)->deactivate(PageSetting::where('page_name', $from)->where('active', true)->sole());
        if ($to !== '') $this->pageId($to, $id);
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
        // Ang "Gone" (id 4001) ay inalis kahapon; ang row na ginawa pagkatapos ay maaaring sa ibang page na kapangalan.
        $this->pageId('Gone', '4001');
        $this->rename('Gone', '');
        PageSetting::where('page_name', 'Gone')->update(['replaced_at' => now()->subDay()]);
        $this->row('Gone', 'p5', null, now()->subDays(2));
        $this->row('Gone', 'p6');
        // Ang pangalan mula sa sheet ay walang control character at may hangganan ang haba sa report.
        $this->row("Bad\x07Name" . str_repeat('x', 100), 'p7');

        $out = $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => null, 'p3' => null, 'p4' => null, 'p5' => '4001', 'p6' => null, 'p7' => null], $this->ids());
        $this->assertStringContainsString('No matching page: 4', $out);
        $this->assertStringContainsString('More than one page id: 1', $out);
        $this->assertStringContainsString('Left without page id: 5', $out);
        $this->assertStringContainsString("  Lost Page: 2" . PHP_EOL, $out, 'pinakamarami muna');
        $this->assertStringContainsString('Shop: 1 (more than one page id)', $out);
        $this->assertStringContainsString('Gone: 1', $out);
        $this->assertStringContainsString('Bad Name' . str_repeat('x', 71) . '…: 1', $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString('p2', $out, 'walang PSID sa output');
    }

    public function test_S_233_2_3_a_row_written_by_a_live_writer_during_the_run_is_not_overwritten(): void
    {
        $this->pageId('Page Uno', '1001');
        $id = $this->row('Page Uno', 'p1');
        // Isinulat ng chat o sync ang page_id sa pagitan ng SELECT at UPDATE ng backfill.
        $done = false;
        DB::listen(function ($q) use (&$done, $id) {
            if ($done || !str_starts_with($q->sql, 'select') || !str_contains($q->sql, 'botcake_psids')) return;
            $done = true;
            DB::table('botcake_psids')->where('id', $id)->update(['page_id' => '7777']);
        });

        $this->backfill();

        $this->assertSame(['p1' => '7777'], $this->ids());
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
        $this->rename('Old Name', 'New Name');
        $this->row(' page   UNO ', 'p1');
        $this->row('PAGE UNO', 'p2');
        $this->row('old name', 'p3', null, now()->subDay());

        $this->backfill();

        $this->assertSame(['p1' => '1001', 'p2' => '1001', 'p3' => '3001'], $this->ids());
    }
}
