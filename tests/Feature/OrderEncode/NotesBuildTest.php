<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AddressNote;
use App\Models\MacroOutput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec 135: order-encode:notes-build gumagawa ng address_notes mula sa mga itinamang (staff, Astra) at na-deliver na order. */
class NotesBuildTest extends OrderEncodeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_06_23_120000_create_ai_checker_logs_table.php',
            'database/migrations/2026_11_06_100000_create_address_notes_table.php',
        ]]);
        Schema::table('macro_output', function (Blueprint $t) {
            $t->text('HISTORICAL LOGS')->nullable();
        });
        Schema::table('from_jnts', function (Blueprint $t) {
            $t->timestamp('updated_at')->nullable();
        });
    }

    private function cxd(string $province, string $city, string $brgy): string
    {
        return "- Name: Maria Santos\n- Phone Number: 09171234567\n- Province: {$province}\n- City: {$city}\n- Brgy: {$brgy}\n- Address: Blk 5";
    }

    private function placed(string $brgy = 'Lahug', array $over = []): MacroOutput
    {
        return MacroOutput::create($over + ['PAGE' => self::PAGE, 'FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567', 'ts_date' => now('Asia/Manila')->toDateString(),
            'PROVINCE' => 'CEBU', 'CITY' => 'CEBU-CITY', 'BARANGAY' => 'LAHUG', 'extracted_details' => $this->cxd('Cebu', 'Cebu City', $brgy)]);
    }

    /** Ang order na itinama ng staff (linya ng HISTORICAL LOGS: oras|user|FIELD|luma|bago). */
    private function staffFixed(string $brgy = 'Lahug', array $over = [], ?string $at = null): MacroOutput
    {
        $at ??= now('Asia/Manila')->format('Y-m-d H:i:s');

        return $this->placed($brgy, $over + ['HISTORICAL LOGS' => "{$at}|Staff Juan|BARANGAY|LAHOG|LAHUG"]);
    }

    private function delivered(MacroOutput $m): MacroOutput
    {
        $m->update(['waybill' => 'WB' . $m->id]);
        DB::table('from_jnts')->insert(['waybill_number' => 'WB' . $m->id, 'status' => 'Delivered', 'updated_at' => now()]);

        return $m;
    }

    private function build(int $days = 60): void
    {
        $this->artisan('order-encode:notes-build', ['--days' => $days])->assertSuccessful();
    }

    private function note(): AddressNote
    {
        return AddressNote::sole();
    }

    public function test_S_135_20_a_staff_fix_makes_a_pending_note_with_the_jnt_answer(): void
    {
        $m = $this->staffFixed();

        $this->build();

        $n = $this->note();
        $this->assertSame('lahug|cebu|cebu', $n->written_key);
        $this->assertSame(['CEBU', 'CEBU-CITY', 'LAHUG'], [$n->province, $n->city, $n->barangay]);
        $this->assertSame(['staff_fix', 'pending', 1, 0, false], [$n->source, $n->status, $n->seen, $n->delivered, $n->conflict]);
        $this->assertSame('Brgy: LAHUG | City: CEBU | Province: CEBU', $n->written_example);
        $this->assertSame([$m->id], $n->order_ids);
    }

    public function test_S_135_21_an_astra_fix_and_a_delivered_order_each_make_their_own_source(): void
    {
        $astra = $this->placed('Banilad', ['BARANGAY' => 'BANILAD']);
        DB::table('ai_checker_logs')->insert(['macro_output_id' => $astra->id, 'outcome' => 'fixed', 'source' => 'single', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_checker_logs')->insert(['macro_output_id' => $this->placed('Lahug')->id, 'outcome' => 'partial', 'source' => 'single', 'created_at' => now(), 'updated_at' => now()]); // hindi fixed
        $this->delivered($this->placed('Bakilid', ['BARANGAY' => 'BAKILID', 'CITY' => 'MANDAUE-CITY']));

        $this->build();

        $this->assertSame(['astra_fix', 'delivered'], AddressNote::orderBy('written_key')->get()->map->source->sort()->values()->all());
        $this->assertSame(2, AddressNote::count()); // ang order na "partial" lang ay hindi nabilang
        $this->assertSame(1, AddressNote::where('source', 'delivered')->sole()->delivered);
    }

    public function test_S_135_22_the_same_order_fixed_and_delivered_counts_once_with_both_sources(): void
    {
        $this->delivered($this->staffFixed());

        $this->build();

        $n = $this->note();
        $this->assertSame([1, 1, 'staff_fix,delivered'], [$n->seen, $n->delivered, $n->source]);
    }

    public function test_S_135_23_another_answer_for_the_same_written_place_is_a_conflict_with_counts(): void
    {
        $this->staffFixed();
        $this->staffFixed();
        $this->staffFixed('Lahug', ['BARANGAY' => 'BANILAD']);

        $this->build();

        $n = $this->note();
        $this->assertSame(['LAHUG', 2, true], [$n->barangay, $n->seen, $n->conflict]);
        $this->assertSame([['province' => 'CEBU', 'city' => 'CEBU-CITY', 'barangay' => 'BANILAD', 'seen' => 1, 'delivered' => 0]], $n->conflicts);
    }

    public function test_S_135_24_auto_approval_needs_two_deliveries_no_conflict_and_a_fix(): void
    {
        // A: dalawang itinamang order na na-deliver -> approved
        $this->delivered($this->staffFixed('Lahug'));
        $this->delivered($this->staffFixed('Lahug'));
        // B: isa lang ang na-deliver -> pending
        $this->delivered($this->staffFixed('Banilad', ['BARANGAY' => 'BANILAD']));
        $this->staffFixed('Banilad', ['BARANGAY' => 'BANILAD']);
        // C: dalawang na-deliver pero walang pagtatama -> pending
        $this->delivered($this->placed('Bakilid', ['BARANGAY' => 'BAKILID', 'CITY' => 'MANDAUE-CITY']));
        $this->delivered($this->placed('Bakilid', ['BARANGAY' => 'BAKILID', 'CITY' => 'MANDAUE-CITY']));
        // D: dalawang itinama at na-deliver pero may conflict -> pending
        $this->delivered($this->staffFixed('Penafrancia', ['PROVINCE' => 'CAMARINES-SUR', 'CITY' => 'NAGA-CITY', 'BARANGAY' => 'PENAFRANCIA']));
        $this->delivered($this->staffFixed('Penafrancia', ['PROVINCE' => 'CAMARINES-SUR', 'CITY' => 'NAGA-CITY', 'BARANGAY' => 'PENAFRANCIA']));
        $this->staffFixed('Penafrancia', ['PROVINCE' => 'METRO-MANILA', 'CITY' => 'MAKATI-CITY', 'BARANGAY' => 'POBLACION']);

        $this->build();

        $status = AddressNote::all()->mapWithKeys(fn ($n) => [$n->barangay => $n->status])->all();
        $this->assertSame(['LAHUG' => 'approved', 'BANILAD' => 'pending', 'BAKILID' => 'pending', 'PENAFRANCIA' => 'pending'], $status);
        $this->assertSame(['auto', null], [AddressNote::where('barangay', 'LAHUG')->sole()->reviewed_by, AddressNote::where('barangay', 'LAHUG')->sole()->reviewed_at]);
    }

    public function test_S_135_25_a_reviewed_note_keeps_its_status_and_its_answer(): void
    {
        $this->staffFixed();
        $this->build();
        $n = $this->note();
        $n->update(['status' => 'rejected', 'reviewed_by' => 'Analyst', 'reviewed_at' => now(), 'barangay' => 'BANILAD']); // ang review: tinanggihan, at ibang sagot

        $this->delivered($this->staffFixed());
        $this->delivered($this->staffFixed());
        $this->build();

        $n = $this->note()->fresh();
        $this->assertSame(['rejected', 'Analyst', 'BANILAD'], [$n->status, $n->reviewed_by, $n->barangay]);
        $this->assertTrue($n->conflict); // ang bagong sagot ay nasa conflict, hindi pumalit
    }

    public function test_S_135_26_running_it_again_does_not_count_an_order_twice(): void
    {
        $this->delivered($this->staffFixed());
        $this->staffFixed();

        $this->build();
        $this->build(3);
        $this->delivered(MacroOutput::find(2)); // na-deliver pagkatapos ng unang takbo
        $this->build();
        $this->build();

        $n = $this->note();
        $this->assertSame([2, 2], [$n->seen, $n->delivered]);
        $this->assertCount(2, $n->order_ids);
    }

    public function test_S_135_27_orders_outside_the_window_without_a_written_place_or_off_the_jnt_list_are_skipped(): void
    {
        $this->staffFixed('Lahug', [], now('Asia/Manila')->subDays(20)->format('Y-m-d H:i:s'));          // pagtatama bago ang saklaw
        $this->staffFixed('Lahug', ['extracted_details' => null, 'CXD' => null]);                         // walang isinulat na lugar
        $this->staffFixed('Lahug', ['BARANGAY' => 'WALANG-GANITO']);                                      // sagot na wala sa J&T list
        $this->placed('Lahug', ['HISTORICAL LOGS' => now('Asia/Manila')->format('Y-m-d H:i:s') . '|Staff|FULL NAME|A|B']); // pagtatama ng pangalan lang

        $this->build(10);

        $this->assertSame(0, AddressNote::count());
    }

    public function test_S_135_28_a_note_holds_only_address_parts_and_order_ids(): void
    {
        $this->staffFixed();

        $this->build();

        $dump = json_encode(AddressNote::sole()->toArray());
        foreach (['Maria', 'Santos', '9171234567', '09171234567', 'Staff Juan'] as $private) $this->assertStringNotContainsString($private, $dump);
    }

    public function test_S_135_29_the_review_command_approves_the_filtered_pending_notes_and_a_dry_run_changes_nothing(): void
    {
        $this->delivered($this->staffFixed());
        $this->staffFixed('Banilad', ['BARANGAY' => 'BANILAD']);
        $this->staffFixed('Banilad', ['BARANGAY' => 'LAHUG']); // conflict sa Banilad
        $this->build();

        $this->artisan('order-encode:notes-review', ['--approve-where' => 'source=staff_fix,delivered>=1', '--reviewer' => 'Analyst', '--dry-run' => true])
            ->expectsOutputToContain('1 note(s) would be approved')->assertSuccessful();
        $this->assertSame(0, AddressNote::where('status', 'approved')->count());

        $this->artisan('order-encode:notes-review', ['--approve-where' => 'source=staff_fix', '--reviewer' => 'Analyst', '--note' => 'checked'])
            ->expectsOutputToContain('approved 1 note(s) as Analyst; 1 with a conflict are left')->assertSuccessful();
        $ok = AddressNote::where('status', 'approved')->sole();
        $this->assertSame(['LAHUG', 'Analyst', 'checked'], [$ok->barangay, $ok->reviewed_by, $ok->review_note]);
        $this->assertNotNull($ok->reviewed_at);

        $this->artisan('order-encode:notes-review', ['--approve-where' => 'nonsense=1', '--reviewer' => 'Analyst'])->assertFailed();
        $this->artisan('order-encode:notes-review', ['--approve-where' => 'status=pending'])->assertFailed(); // walang --reviewer
    }
}
