<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AddressNote;
use App\Models\AppSetting;
use App\Services\OrderEncode\Settings;
use App\Services\OrderEncode\Written;
use Illuminate\Support\Facades\Artisan;

/** Spec 135: /order-encode/notes (CEO): hanap, filter, approve, reject, palitan ang sagot, bulk approve; at ang NOTE_HIT / hold sa /order-encode/final. */
class NotesPageTest extends FinalTestCase
{
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_06_100000_create_address_notes_table.php']]);
    }

    private function as(string $role = 'CEO')
    {
        $this->users[$role] ??= $this->user($role, strtolower(str_replace(' ', '', $role)) . '@example.test');

        return $this->actingAs($this->users[$role]);
    }

    private function note(string $brgy, array $over = [], array $answer = ['CEBU', 'CEBU-CITY', 'LAHUG']): AddressNote
    {
        $key = Written::key(['province' => 'CEBU', 'city' => 'CEBU', 'barangay' => $brgy]);
        [$b, $c, $p] = Written::split($key);

        return AddressNote::create($over + ['written_key' => $key, 'written_brgy' => $b, 'written_city' => $c, 'written_province' => $p,
            'written_example' => "Brgy: {$brgy} | City: CEBU | Province: CEBU", 'province' => $answer[0], 'city' => $answer[1], 'barangay' => $answer[2],
            'source' => 'staff_fix', 'seen' => 2, 'delivered' => 1, 'status' => 'pending', 'order_ids' => [11, 12]]);
    }

    public function test_S_135_40_only_the_ceo_can_open_or_use_the_page(): void
    {
        $n = $this->note('Lahug');

        $this->get('/order-encode/notes')->assertRedirect(); // bisita
        foreach (['Marketing - OIC', 'Marketing', 'Data Encoder'] as $role) {
            $this->as($role)->get('/order-encode/notes')->assertNotFound();
            $this->as($role)->post("/order-encode/notes/{$n->id}/approve")->assertNotFound();
            $this->as($role)->post('/order-encode/notes/bulk', ['scope' => 'filtered'])->assertNotFound();
        }
        $this->assertSame('pending', $n->fresh()->status);
        $this->as('CEO')->get('/order-encode/notes')->assertOk();
    }

    public function test_S_135_41_it_lists_pending_first_with_counts_and_filters_and_searches(): void
    {
        $this->note('Lahug');
        $this->note('Banilad', ['status' => 'approved', 'source' => 'astra_fix', 'delivered' => 3, 'seen' => 4]);
        $this->note('Pasil', ['conflict' => true, 'conflicts' => [['province' => 'CEBU', 'city' => 'CEBU-CITY', 'barangay' => 'BANILAD', 'seen' => 1, 'delivered' => 0]], 'source' => 'staff_fix,delivered']);
        $this->note('Rejected one', ['status' => 'rejected']);

        $r = $this->as()->get('/order-encode/notes')->assertOk();
        $this->assertSame(['Lahug', 'Pasil'], $r->viewData('notes')->pluck('written_brgy')->map(fn ($b) => ucfirst($b))->sort()->values()->all()); // pending lang sa una
        $this->assertSame(['pending' => 2, 'approved' => 1, 'rejected' => 1], $r->viewData('counts'));
        $r->assertSee('conflict')->assertSee('BANILAD');

        $names = fn (string $q) => $this->as()->get('/order-encode/notes?' . $q)->viewData('notes')->pluck('written_brgy')->sort()->values()->all();
        $this->assertSame(['banilad'], $names('status=approved'));
        $this->assertSame(['banilad'], $names('status=all&source=astra_fix'));
        $this->assertSame(['pasil'], $names('status=all&source=delivered'));
        $this->assertSame(['pasil'], $names('status=all&conflict=yes'));
        $this->assertSame(['banilad'], $names('status=all&delivered=3'));
        $this->assertSame(['banilad', 'lahug', 'pasil', 'rejected one'], $names('status=all'));
        $this->assertSame(['pasil'], $names('status=all&q=PASIL'));
        $this->assertSame(['banilad', 'lahug', 'pasil', 'rejected one'], $names('status=all&q=cebu-city'));
    }

    public function test_S_135_42_approve_and_reject_record_the_reviewer_and_the_note(): void
    {
        $a = $this->note('Lahug');
        $b = $this->note('Pasil');

        $this->as()->post("/order-encode/notes/{$a->id}/approve", ['review_note' => 'checked with the map'])->assertRedirect();
        $this->as()->post("/order-encode/notes/{$b->id}/reject")->assertRedirect();

        $a = $a->fresh();
        $this->assertSame(['approved', 'CEO User', 'checked with the map'], [$a->status, $a->reviewed_by, $a->review_note]);
        $this->assertNotNull($a->reviewed_at);
        $this->assertSame(['rejected', 'CEO User'], [$b->fresh()->status, $b->fresh()->reviewed_by]);
    }

    public function test_S_135_43_the_answer_can_only_be_changed_to_a_jnt_combination(): void
    {
        $n = $this->note('Lahug');

        $this->as()->from('/order-encode/notes')->post("/order-encode/notes/{$n->id}/edit", ['province' => 'CEBU', 'city' => 'CEBU-CITY', 'barangay' => 'WALANG-GANITO'])
            ->assertRedirect('/order-encode/notes')->assertSessionHasErrors('edit');
        $this->assertSame('LAHUG', $n->fresh()->barangay);

        $this->as()->post("/order-encode/notes/{$n->id}/edit", ['province' => 'cebu', 'city' => 'cebu city', 'barangay' => 'banilad', 'review_note' => 'wrong barangay'])->assertSessionHasNoErrors();
        $n = $n->fresh();
        $this->assertSame(['CEBU', 'CEBU-CITY', 'BANILAD', 'CEO User', 'wrong barangay'], [$n->province, $n->city, $n->barangay, $n->reviewed_by, $n->review_note]); // ang label ng J&T
        $this->assertSame('pending', $n->status);
        $this->assertTrue($n->conflict);
        $this->assertSame('LAHUG', $n->conflicts[0]['barangay']); // ang dating sagot ay hindi nawala
    }

    public function test_S_135_44_bulk_approve_takes_the_ticked_or_the_filter_and_leaves_conflicts_to_a_person(): void
    {
        $a = $this->note('Lahug', ['delivered' => 5]);
        $b = $this->note('Banilad', ['delivered' => 5]);
        $c = $this->note('Pasil', ['delivered' => 5, 'conflict' => true, 'conflicts' => [['province' => 'CEBU', 'city' => 'CEBU-CITY', 'barangay' => 'BANILAD', 'seen' => 1, 'delivered' => 0]]]);
        $d = $this->note('Bakilid', ['delivered' => 0]);

        $this->as()->post('/order-encode/notes/bulk', ['scope' => 'selected', 'ids' => [$a->id, $c->id], 'review_note' => 'bulk'])->assertSessionHas('status', 'Approved 1; 1 with a conflict need a person.');
        $this->assertSame(['approved', 'pending', 'pending', 'pending'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status, $d->fresh()->status]);
        $this->assertSame(['CEO User', 'bulk'], [$a->fresh()->reviewed_by, $a->fresh()->review_note]);

        $this->as()->post('/order-encode/notes/bulk', ['scope' => 'filtered', 'delivered' => '3'])->assertSessionHas('status', 'Approved 1; 1 with a conflict need a person.');
        $this->assertSame(['approved', 'approved', 'pending', 'pending'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status, $d->fresh()->status]);

        $this->as()->get('/order-encode/notes?delivered=3')->assertSee('Approve all 0 pending'); // ang bilang sa button
    }

    public function test_S_135_48_a_percent_in_the_search_is_literal_and_a_filtered_bulk_needs_a_filter(): void
    {
        $this->note('Lahug');
        $this->note('Banilad');

        $this->assertSame([], $this->as()->get('/order-encode/notes?status=all&q=%25')->viewData('notes')->pluck('id')->all());
        $this->as()->post('/order-encode/notes/bulk', ['scope' => 'filtered'])->assertSessionHasErrors('bulk');
        $this->assertSame(0, AddressNote::where('status', 'approved')->count());
    }

    public function test_S_135_45_text_from_customers_is_escaped(): void
    {
        $this->note('Lahug', ['written_example' => 'Brgy: <script>alert(1)</script> | City: <img src=x onerror=1>', 'review_note' => '<b>bold</b>']);

        $html = $this->as()->get('/order-encode/notes')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_S_135_46_the_final_page_shows_note_hit_and_the_rule_that_held_an_order(): void
    {
        $this->encoded($this->macro(['PROVINCE' => 'BOHOL']), ['note_hit' => true, 'hold' => null]);
        $this->encoded($this->macro(), ['proceed' => false, 'hold' => ['rule' => 'R5', 'reason' => 'Possible duplicate: 7, 9']]);
        \Illuminate\Support\Facades\Artisan::call('order-encode:compare-final');

        $r = $this->as()->get('/order-encode/final?from=' . now()->subDay()->toDateString() . '&to=' . now()->toDateString())->assertOk();

        $r->assertSee('NOTE_HIT')->assertSee('held by R5: Possible duplicate: 7, 9');
        $this->assertSame(1, $r->viewData('tiles')['note_hits']);
        $this->assertSame(['R5' => 1], $r->viewData('ruleHolds'));
    }

    public function test_S_135_47_the_ceo_can_switch_the_rules_on_the_final_page(): void
    {
        $this->as()->post('/order-encode/import-settings', ['switch' => 'off', 'cap' => 10, 'rules' => 'sheet'])->assertRedirect();
        $this->assertSame('sheet', app(Settings::class)->rules());

        $this->as()->post('/order-encode/import-settings', ['switch' => 'off', 'cap' => 10, 'rules' => 'nonsense'])->assertSessionHasErrors('rules');
        $this->as()->post('/order-encode/import-settings', ['switch' => 'off', 'cap' => 10])->assertRedirect(); // walang rules: hindi ginagalaw
        $this->assertSame('sheet', AppSetting::get(Settings::RULES));
    }
}
