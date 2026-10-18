<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Models\ChatblastMessage;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast/sequence: ang mga field ng schedule (mga araw ng linggo, cap) na isa-isang sine-save, ang mga step (mensahe
 * ng bawat isa, idagdag, alisin, ilipat, sariling oras), ang text ng hindi pa naipapadala na sumusunod, at ang buod at
 * susunod na takbo sa data. 2026-10-18 ay Linggo.
 */
class ChatblastSequenceStepsTest extends ChatblastTestCase
{
    private ChatblastCampaign $c;
    /** @var int[] mensahe ng Day 1, 2, 3 */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 09:30:00');
        $this->page('Page Uno');
        $this->c = ChatblastCampaign::where('kind', 'in_transit')->sole();
        $this->ids = array_map(fn ($name, $versions) => ChatblastMessage::create(['name' => $name, 'versions' => $versions])->id,
            ['Una', 'Dalawa', 'Tatlo'], [['Una A {name}', 'Una B {name}'], ['Dalawa A {name}'], ['Tatlo A {name}']]);
        $this->c->update(['step_messages' => $this->ids, 'days' => [['Una A {name}', 'Una B {name}'], ['Dalawa A {name}'], ['Tatlo A {name}']]]);
    }

    private function url(string $tail = ''): string
    {
        return '/chatblast/sequence/' . $this->c->id . $tail;
    }

    private function row(array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'in_transit', 'campaign_id' => $this->c->id, 'day' => 1, 'version' => 'A',
            'psid' => 'psid-1', 'vars' => json_encode(['name' => 'Ana', 'waybill' => 'WB-9']), 'text' => 'luma', 'status' => 'ready',
            'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_schedule_fields_save_one_by_one_with_validation_and_a_log(): void
    {
        $this->postJson($this->url(), ['weekdays' => [5, 1, 3]])->assertOk();
        $this->assertSame([1, 3, 5], $this->c->fresh()->weekdays);
        $this->postJson($this->url(), ['weekdays' => [1, 2, 3, 4, 5, 6, 7]])->assertOk();
        $this->assertNull($this->c->fresh()->weekdays, 'lahat ng araw = araw-araw');
        $this->postJson($this->url(), ['daily_cap' => 50])->assertOk();
        $this->assertSame(50, $this->c->fresh()->daily_cap);
        $this->postJson($this->url(), ['daily_cap' => null])->assertOk();
        $this->assertNull($this->c->fresh()->daily_cap);

        foreach ([['weekdays' => []], ['weekdays' => [0]], ['weekdays' => [8]], ['weekdays' => 'Lun'], ['daily_cap' => 0], ['daily_cap' => -5], ['daily_cap' => 'x'], ['daily_cap' => 100001]] as $body) {
            $this->postJson($this->url(), $body)->assertStatus(422);
        }
        $fields = collect($this->c->fresh()->log)->where('what', 'settings')->pluck('fields')->flatten()->unique()->values()->all();
        $this->assertSame(['weekdays', 'daily_cap'], $fields);
    }

    public function test_steps_are_picked_added_removed_and_moved_with_their_own_times(): void
    {
        [$una, $dalawa, $tatlo] = $this->ids;
        $this->postJson($this->url('/step-times'), ['day' => 2, 'times' => ['15:00', '10:00']])->assertOk();
        $this->assertSame([null, ['10:00', '15:00'], null], $this->c->fresh()->step_times);

        $this->postJson($this->url('/step'), ['after' => 1])->assertOk(); // bagong Day 2: ang mensahe ng Day 1
        $this->assertSame([$una, $una, $dalawa, $tatlo], $this->c->fresh()->step_messages);
        $this->assertSame([null, null, ['10:00', '15:00'], null], $this->c->fresh()->step_times);
        $this->postJson($this->url('/step-message'), ['day' => 2, 'message_id' => $tatlo])->assertOk();
        $this->assertSame([['Una A {name}', 'Una B {name}'], ['Tatlo A {name}'], ['Dalawa A {name}'], ['Tatlo A {name}']], $this->c->fresh()->days, 'ang days ay ang text ng mga mensahe');

        $this->postJson($this->url('/move'), ['day' => 3, 'dir' => 'up'])->assertOk();
        $this->assertSame([$una, $dalawa, $tatlo, $tatlo], $this->c->fresh()->step_messages);
        $this->assertSame([null, ['10:00', '15:00'], null, null], $this->c->fresh()->step_times, 'kasama ang oras sa paglipat');

        $this->postJson($this->url('/remove-step'), ['day' => 2])->assertOk();
        $this->assertSame([$una, $tatlo, $tatlo], $this->c->fresh()->step_messages);
        $this->assertNull($this->c->fresh()->step_times, 'walang step na may sariling oras');

        foreach ([['/move', ['day' => 1, 'dir' => 'up']], ['/move', ['day' => 2, 'dir' => 'gilid']], ['/step-times', ['day' => 1, 'times' => ['25:00']]],
            ['/step-times', ['day' => 9, 'times' => []]], ['/step', ['after' => 7]], ['/remove-step', ['day' => 5]], ['/step-message', ['day' => 1, 'message_id' => 999999]]] as [$path, $body]) {
            $this->postJson($this->url($path), $body)->assertStatus(422);
        }
        $this->postJson($this->url('/remove-step'), ['day' => 2])->assertOk();
        $this->postJson($this->url('/remove-step'), ['day' => 2])->assertOk();
        $this->postJson($this->url('/remove-step'), ['day' => 1])->assertStatus(422); // kahit isang step
        $this->assertSame([$una], $this->c->fresh()->step_messages);
        $this->assertSame(['add_step', 'move', 'remove_step', 'step_message', 'step_times'],
            collect($this->c->fresh()->log)->pluck('what')->intersect(['add_step', 'move', 'remove_step', 'step_message', 'step_times'])->unique()->sort()->values()->all());
    }

    public function test_moving_a_step_rewrites_the_unsent_rows_and_a_new_past_time_does_not_fire(): void
    {
        $unsent = $this->row(['day' => 2]);
        $sent = $this->row(['day' => 2, 'status' => 'sent', 'sent_at' => now()]);
        $this->postJson($this->url('/move'), ['day' => 2, 'dir' => 'down'])->assertOk();
        $this->assertSame('Tatlo A Ana', BotcakeMessage::find($unsent)->text);
        $this->assertSame('luma', BotcakeMessage::find($sent)->text);

        $this->c->update(['mode' => 'auto', 'send_times' => ['15:00'], 'auto_ran_at' => now()->setTime(8, 0)]);
        $this->postJson($this->url('/step-times'), ['day' => 1, 'times' => ['09:00']])->assertOk();
        $this->assertTrue($this->c->fresh()->auto_ran_at->eq(now()->setTime(9, 0)), 'ang 09:00 na lumipas na ay hindi tatakbo');
        $this->postJson($this->url(), ['weekdays' => [1]])->assertOk(); // walang bagong oras ngayong Linggo
        $this->assertTrue($this->c->fresh()->auto_ran_at->eq(now()->setTime(9, 0)));
    }

    public function test_the_data_gives_each_sequence_its_steps_summary_next_run_and_the_message_list(): void
    {
        $this->row(['status' => 'sent', 'sent_at' => now()]);
        $this->row(['day' => 2, 'status' => 'skipped']);
        $this->c->update(['mode' => 'auto', 'send_times' => ['09:15', '15:00'], 'step_times' => [['10:00'], null, null]]);

        $d = $this->getJson('/chatblast/sequence/data')->assertOk()->json();
        $mine = collect($d['campaigns'])->firstWhere('id', $this->c->id);
        $this->assertSame('Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM', $mine['summary']);
        $this->assertSame('2026-10-18 10:00', $mine['next_run']);
        $this->assertSame([[1, 'Una', ['10:00'], 1, 0], [2, 'Dalawa', null, 0, 1]],
            array_map(fn ($s) => [$s['day'], $s['message'], $s['times'], $s['today']['sent'], $s['today']['skipped']], array_slice($mine['steps'], 0, 2)));
        $this->assertSame(['built' => 2, 'sent' => 1, 'failed' => 0, 'skipped' => 1], $mine['today']);
        $this->assertContains('Una', array_column($d['messages'], 'name'));
    }
}
