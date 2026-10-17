<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Mga araw kada uri: Araw 1, Araw 2, … bawat isa may isa o higit pang bersyon ng text. in_transit: isang mensahe kada
 * waybill kada araw; ang araw = ilan na ang naipadala + 1; humihinto kapag ubos na ang araw o hindi na In Transit.
 */
class ChatblastSequenceTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->page('Page Uno');
        $this->travelTo('2026-10-18 09:00:00');
    }

    private function build(string $kind = 'in_transit'): array
    {
        return app(Chatblast::class)->build($kind, 1);
    }

    private function rows(string $kind = 'in_transit')
    {
        return BotcakeMessage::where('kind', $kind)->where('is_test', false)->orderBy('id')->get();
    }

    public function test_one_in_transit_message_per_day_numbered_by_the_ones_sent_until_the_days_run_out(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $defaults = app(Chatblast::class)->sequence('in_transit');
        $this->assertCount(5, $defaults, 'limang araw ang default');
        $this->assertCount(5, array_unique(array_map(fn ($d) => $d[0], $defaults)), 'iba-iba ang text kada araw');

        $this->assertSame(1, $this->build()['added']);
        $this->assertSame(0, $this->build()['added'], 'isa lang kada araw');
        $row = $this->rows()->sole();
        $this->assertSame([1, 'A', 'WB-001|in_transit|1'], [$row->day, $row->version, $row->dedupe_key]);
        $this->assertSame(app(Chatblast::class)->render($defaults[0][0], (array) $row->vars), $row->text);

        $row->update(['status' => 'sent', 'sent_at' => now()]);
        $this->assertSame(0, $this->build()['added'], 'naipadala na ngayong araw');

        for ($day = 2; $day <= 5; $day++) {
            $this->travel(1)->days();
            $this->assertSame(1, $this->build()['added']);
            $last = $this->rows()->last();
            $this->assertSame($day, $last->day);
            $last->update(['status' => 'sent', 'sent_at' => now()]);
        }

        $this->travel(1)->days();
        $this->assertSame(0, $this->build()['added'], 'ubos na ang 5 araw');
        $this->assertCount(5, $this->rows());
    }

    public function test_an_unsent_day_is_not_repeated_the_next_day(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $this->build();

        $this->travel(1)->days();
        $this->assertSame(0, $this->build()['added'], 'hindi pa naipapadala ang Araw 1: walang Araw 1 ulit');
        $this->assertSame([1], $this->rows()->pluck('day')->all());
    }

    public function test_the_messages_stop_when_the_waybill_is_no_longer_in_transit(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', 'psid-b', 'In Transit');
        $this->build();
        $this->rows()->firstWhere('waybill', 'WB-001')->update(['status' => 'sent', 'sent_at' => now()]);

        DB::table('from_jnts')->update(['status' => 'Delivering']);
        $this->travel(1)->days();

        $this->assertSame(0, $this->build()['added'], 'Delivering na: walang Araw 2');
        $unsent = $this->rows()->firstWhere('waybill', 'WB-002');
        $this->assertSame('skipped', $unsent->status);
        $this->assertStringContainsString('Delivering', $unsent->reason);
        $this->assertSame(2, $this->build('delivering')['added'], 'ang delivering na ang kumukuha');
    }

    public function test_the_sender_skips_a_message_whose_waybill_changed_status_before_it_was_sent(): void
    {
        Http::fake();
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $this->build();
        DB::table('from_jnts')->update(['status' => 'Delivered']);

        app(ChatblastSender::class)->queue([$this->rows()->sole()->id], 1);

        Http::assertNothingSent();
        $this->assertSame('skipped', $this->rows()->sole()->status);
        $this->assertStringContainsString('Delivered', $this->rows()->sole()->reason);
    }

    public function test_versions_rotate_across_neighbouring_waybills_and_a_rebuild_keeps_the_same_version(): void
    {
        $chatblast = app(Chatblast::class);
        $chatblast->setSequence('in_transit', [['Una {name}', 'Ikalawa {name}', 'Ikatlo {name}'], ['Araw dalawa']]);
        foreach (range(101, 106) as $n) $this->shipped('Page Uno', 'Buyer ' . $n, 'JT0000000' . $n, 'psid-' . $n, 'In Transit');

        $this->build();

        $versions = $this->rows()->sortBy('waybill')->pluck('version')->values()->all();
        foreach (range(1, 5) as $i) $this->assertNotSame($versions[$i - 1], $versions[$i], 'magkatabing waybill: ibang bersyon');
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], array_values(array_unique($versions)));
        $this->assertStringStartsWith(['A' => 'Una', 'B' => 'Ikalawa', 'C' => 'Ikatlo'][$versions[0]], $this->rows()->sortBy('waybill')->first()->text);

        $chatblast->setSequence('in_transit', [['Una {name}', 'Ikalawa {name}', 'Ikatlo {name}'], ['Araw dalawa binago']]);
        $this->assertSame($versions, $this->rows()->sortBy('waybill')->pluck('version')->values()->all(), 'parehong bersyon pagkatapos buuin ulit');
    }

    public function test_rider_placeholders_fall_back_to_a_version_without_them_or_no_config(): void
    {
        $chatblast = app(Chatblast::class);
        $chatblast->setSequence('in_transit', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');

        $this->build();

        $row = $this->rows()->sole();
        $this->assertSame(['B', 'Walang rider Ana Cruz', 'ready'], [$row->version, $row->text, $row->status]);

        $chatblast->setSequence('in_transit', [['Rider {rider}', 'Tawag {rider_phone}']]);
        $row = $row->fresh();
        $this->assertSame(['no_config', 'walang rider info'], [$row->status, $row->reason]);
    }

    public function test_the_editor_saves_the_days_and_refuses_empty_ones(): void
    {
        $this->actingAs($this->user());
        $ok = ['kind' => 'in_transit', 'days' => [['Araw isa A', 'Araw isa B'], ['Araw dalawa']]];

        $this->postJson('/chatblast/sequence', $ok)->assertOk();
        $this->assertSame($ok['days'], app(Chatblast::class)->sequence('in_transit'));

        foreach ([
            'walang araw'          => ['kind' => 'in_transit', 'days' => []],
            'araw na walang bersyon' => ['kind' => 'in_transit', 'days' => [['A'], []]],
            'blangkong text'       => ['kind' => 'in_transit', 'days' => [['  ']]],
            'masyadong mahaba'     => ['kind' => 'in_transit', 'days' => [[str_repeat('x', 1001)]]],
            'hindi listahan'       => ['kind' => 'in_transit', 'days' => 'x'],
            'hindi kilalang uri'   => ['kind' => 'iba', 'days' => [['A']]],
        ] as $case => $body) {
            $this->postJson('/chatblast/sequence', $body)->assertStatus(422);
        }
        $this->assertSame($ok['days'], app(Chatblast::class)->sequence('in_transit'), 'walang nabago sa maling input');

        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $this->build();
        $row = $this->getJson('/chatblast/list?' . http_build_query(['kind' => 'in_transit']))->assertOk()->json('rows.0');
        $this->assertSame([1, 'B', 'Araw 1 · B'], [$row['day'], $row['version'], $row['step']], 'WB-001: 1 mod 2 = bersyon B');
    }
}
