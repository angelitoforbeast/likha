<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AddressNote;
use App\Services\OrderEncode\Notes;
use App\Services\OrderEncode\Written;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Spec 135: ang encoder ay gumagamit ng approved na address_notes: NOTE_HIT, mga halimbawa sa prompt ng GPT, at index lang ang ginagamit sa paghahanap. */
class NotesUseTest extends EncodeRunTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_06_100000_create_address_notes_table.php']]);
    }

    /** @param array{province?: string, city?: string, barangay?: string} $written */
    private function note(array $written, array $answer, string $status = 'approved', array $over = []): AddressNote
    {
        $key = Written::key($written);
        [$b, $c, $p] = Written::split($key);

        return AddressNote::create($over + [
            'written_key' => $key, 'written_brgy' => $b, 'written_city' => $c, 'written_province' => $p, 'written_example' => Written::example($written),
            'province' => $answer[0], 'city' => $answer[1], 'barangay' => $answer[2], 'source' => 'staff_fix', 'seen' => 3, 'delivered' => 2, 'status' => $status,
        ]);
    }

    private static function bungad(): array
    {
        return ['province' => 'METRO MANILA', 'city' => '', 'barangay' => 'BUNGAD'];
    }

    private function gptAnswers(): array
    {
        return ['province' => ['province' => 'METRO-MANILA'], 'city' => ['city' => 'CALOOCAN-CITY', 'province' => 'METRO-MANILA'], 'barangay' => ['barangay' => 'BUNGAD']];
    }

    public function test_S_135_30_an_approved_note_sets_the_place_and_skips_the_gpt_location_fixes(): void
    {
        $this->note(self::bungad(), ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD']);
        $cxd = $this->cxd('Metro Manila', '', 'Bungad'); // walang city: may remark, kaya may pag-ayos sana

        $without = $this->runOrder($cxd, self::bungadRows(), $this->gptAnswers(), 'sheet');
        $this->assertContains('city', $this->tasks);
        $this->assertSame('CALOOCAN-CITY', $without->outputs['city']); // ang GPT ang pumili

        $o = $this->runOrder($cxd, self::bungadRows(), $this->gptAnswers());
        $this->assertSame(['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'], [$o->outputs['province'], $o->outputs['city'], $o->outputs['barangay']]);
        $this->assertSame([], array_values(array_intersect($this->tasks, ['province', 'city', 'barangay'])));
        $this->assertContains('verify', $this->tasks); // tumatakbo pa ang checker
        $this->assertSame([true, true], [$o->outputs['note_hit'], $o->outputs['proceed']]);
        $hit = $this->step($o, 'note_hit');
        $this->assertSame(['NOTE_HIT', 'bungad||manila'], [$hit['status'], $hit['key']]);
    }

    public function test_S_135_31_pending_and_rejected_notes_and_other_written_places_are_not_used(): void
    {
        $this->note(self::bungad(), ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'], 'pending');
        $this->note(['province' => 'METRO MANILA', 'city' => '', 'barangay' => 'PROJECT 7'], ['METRO-MANILA', 'QUEZON-CITY', 'PROJECT 7']);

        $o = $this->runOrder($this->cxd('Metro Manila', '', 'Bungad'), self::bungadRows(), $this->gptAnswers());

        $this->assertFalse($o->outputs['note_hit']);
        $this->assertContains('city', $this->tasks);
        $this->note(self::bungad(), ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'], 'rejected', ['written_key' => 'x']); // iba ang key, rejected
        $this->assertSame(0, AddressNote::where('written_key', Written::key(self::bungad()))->where('status', 'approved')->count());
    }

    public function test_S_135_32_a_note_whose_answer_is_not_a_jnt_combination_still_holds(): void
    {
        $this->note(self::bungad(), ['METRO-MANILA', 'QUEZON-CITY', 'WALANG-GANITO']);

        $o = $this->runOrder($this->cxd('Metro Manila', '', 'Bungad'), self::bungadRows(), $this->gptAnswers());

        $this->assertTrue($o->outputs['note_hit']);
        $this->assertFalse($o->outputs['proceed']);
        $this->assertSame('R4', $o->outputs['hold']['rule']);
    }

    public function test_S_135_33_at_most_five_closest_approved_notes_go_to_the_gpt_prompts_as_past_corrections(): void
    {
        // pitong approved na note na may parehong isinulat na province; ang dalawa ay mas malapit (parehong city)
        foreach (range(1, 5) as $i) $this->note(['province' => 'METRO MANILA', 'city' => '', 'barangay' => "ZONE {$i}"], ['METRO-MANILA', 'QUEZON-CITY', "ZONE {$i}"]);
        $this->note(['province' => 'METRO MANILA', 'city' => 'CALOOCAN', 'barangay' => 'NEAR ONE'], ['METRO-MANILA', 'CALOOCAN-CITY', 'NEAR ONE']);
        $this->note(['province' => 'METRO MANILA', 'city' => 'CALOOCAN', 'barangay' => 'NEAR TWO'], ['METRO-MANILA', 'CALOOCAN-CITY', 'NEAR TWO']);
        $this->note(['province' => 'METRO MANILA', 'city' => '', 'barangay' => 'PENDING ONE'], ['METRO-MANILA', 'QUEZON-CITY', 'PENDING ONE'], 'pending');
        $this->note(['province' => 'CEBU', 'city' => '', 'barangay' => 'OTHER PROVINCE'], ['CEBU', 'CEBU-CITY', 'LAHUG']);

        // may city na isinulat na mali, kaya tatakbo ang city fix at ang barangay fix
        $o = $this->runOrder($this->cxd('Metro Manila', 'Caloocan', 'Walang Ganito'), self::bungadRows(), $this->gptAnswers());

        $this->assertNotEmpty(array_intersect($this->tasks, ['city', 'barangay']));
        $prompt = $this->prompts['barangay'] ?? $this->prompts['city'];
        $this->assertStringContainsString('Past corrections (customer wrote → correct J&T address):', $prompt);
        $this->assertSame(6, substr_count($prompt, '→')); // ang header + limang halimbawa
        $this->assertStringContainsString('NEAR ONE', $prompt);
        $this->assertStringContainsString('NEAR TWO', $prompt);
        $this->assertStringNotContainsString('PENDING ONE', $prompt);
        $this->assertStringNotContainsString('OTHER PROVINCE', $prompt);
        $this->assertSame(5, count(app(Notes::class)->examples(Written::key(['province' => 'METRO MANILA', 'city' => 'CALOOCAN', 'barangay' => 'X']))));
    }

    public function test_S_135_34_without_notes_or_in_sheet_mode_the_prompts_have_no_past_corrections(): void
    {
        $this->note(['province' => 'METRO MANILA', 'city' => '', 'barangay' => 'ZONE 1'], ['METRO-MANILA', 'QUEZON-CITY', 'ZONE 1']);

        $this->runOrder($this->cxd('Metro Manila', 'Caloocan', 'Walang Ganito'), self::bungadRows(), $this->gptAnswers(), 'sheet');
        $this->assertNotEmpty($this->prompts);
        foreach ($this->prompts as $prompt) $this->assertStringNotContainsString('Past corrections', $prompt);

        AddressNote::query()->delete();
        $this->prompts = [];
        $this->runOrder($this->cxd('Metro Manila', 'Caloocan', 'Walang Ganito'), self::bungadRows(), $this->gptAnswers());
        foreach ($this->prompts as $prompt) $this->assertStringNotContainsString('Past corrections', $prompt);
    }

    public function test_S_135_35_both_lookups_use_an_index(): void
    {
        $this->note(self::bungad(), ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD']);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (preg_match('/from ["`]address_notes["`]/i', $q->sql)) $queries[] = [$q->sql, $q->bindings];
        });

        $notes = new Notes();
        $notes->exact(Written::key(self::bungad()));
        $notes->examples(Written::key(['province' => 'METRO MANILA', 'city' => 'CALOOCAN', 'barangay' => 'X']));

        $selects = array_values(array_filter($queries, fn ($q) => str_starts_with(strtolower($q[0]), 'select')));
        $this->assertGreaterThanOrEqual(3, count($selects)); // exact, at ang dalawa ng examples (city, province)
        foreach ($selects as [$sql, $bindings]) {
            $plan = implode(' | ', array_map(fn ($r) => $r->detail, DB::select('EXPLAIN QUERY PLAN ' . $sql, $bindings)));
            $this->assertStringContainsString('USING', $plan, $sql);
            $this->assertStringNotContainsString('SCAN address_notes', $plan, $sql);
        }
    }
}
