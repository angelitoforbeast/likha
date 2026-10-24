<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\RunSpotAstra;
use App\Models\AppSetting;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceSubscription;
use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\OrderEncode\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Spec 136 E: ang hold dahil sa address ay nagtatanong sa customer nang isang beses kada row, sa sequence ng setting. */
class AddressQuestionTest extends LiveRowTestCase
{
    private function subscriptions(): array
    {
        return ChatbotSequenceSubscription::orderBy('id')->get()->map(fn ($s) => [$s->sequence_id, (int) $s->psid, $s->status, $s->enrolled_via])->all();
    }

    public function test_S_136_29_an_address_hold_after_astra_subscribes_once_with_the_event_and_the_note(): void
    {
        $seq = $this->addressSequence();
        $o = $this->heldOrder();
        $this->astra(code: 'Barangay');

        $this->runAstra($o);

        $this->assertSame([[$seq->id, self::PSID, 'active', 'order-encode']], $this->subscriptions());
        $this->assertSame('subscribed', $o->fresh()->input['asked']['result']);
        $this->assertContains('address question: subscribed', $o->fresh()->input['live_notes']);
        $this->assertSame('subscribed', json_decode(DB::table('chatbot_events')->where('type', 'sequence')->sole()->detail, true)['op']);
    }

    public function test_S_136_30_a_duplicate_hold_asks_nothing_even_when_astra_leaves_the_address_open(): void
    {
        $this->addressSequence();
        $this->existing(['botcake_psid' => '9999', 'fb_name' => 'Other', 'STATUS' => 'PROCEED']); // ibang customer, parehong phone
        $o = $this->liveOrder($this->details());
        $this->astra(code: 'Barangay');

        $this->runAstra($o);

        $this->assertSame('R5', $o->outputs['hold']['rule']);
        $this->assertSame([], $this->subscriptions());
    }

    public function test_S_136_30_31_astra_leaving_the_address_fine_but_not_proceed_asks_nothing(): void
    {
        $this->addressSequence();
        $o = $this->heldOrder();
        $this->astra(code: '✅'); // ang address ay ✅; hold dahil sa pangalan, phone o presyo

        $this->runAstra($o);

        $this->assertSame([], $this->subscriptions());
    }

    /** @return array<string, array{0: callable}> */
    public static function noQuestion(): array
    {
        return [
            'no sequence chosen' => [fn () => null, 'no address question sequence set'],
            'paused' => [fn ($t) => $t->addressSequence(['active' => false]), 'the sequence is paused'],
            'deleted' => [fn ($t) => tap($t->addressSequence())->delete(), 'the sequence was deleted'],
            'no steps' => [fn ($t) => $t->addressSequence([], false), 'the sequence has no active steps'],
        ];
    }

    /** @dataProvider noQuestion */
    public function test_S_136_32_without_a_usable_sequence_the_row_stays_for_staff_with_the_reason(callable $setup, string $reason): void
    {
        AppSetting::set(Settings::SPOT_ASTRA, 'off');
        $setup($this);

        $o = $this->heldOrder();

        $this->assertSame([[], null, 'done'], [$this->subscriptions(), MacroOutput::sole()->STATUS, $o->status]);
        $this->assertStringContainsString($reason, implode(' ', $o->input['live_notes']));
        $this->actingAs($this->user('CEO'))->get('/order-encode?mode=live')->assertOk()->assertSee($reason);
    }

    public function test_S_136_33_an_already_subscribed_customer_is_not_subscribed_again(): void
    {
        AppSetting::set(Settings::SPOT_ASTRA, 'off');
        $seq = $this->addressSequence();
        app(ChatbotSequences::class)->subscribe($seq->id, (int) self::PAGE_ID, self::PSID, 'inbox');

        $o = $this->heldOrder();

        $this->assertSame([[$seq->id, self::PSID, 'active', 'inbox']], $this->subscriptions());
        $this->assertSame('already', $o->input['asked']['result']);
    }

    public function test_S_136_34_35_37_39_a_reply_that_is_still_held_is_encoded_again_but_never_asked_twice(): void
    {
        AppSetting::set(Settings::SPOT_ASTRA, 'off');
        $seq = $this->addressSequence();
        $first = $this->heldOrder([4 => 'order po']);
        app(ChatbotSequences::class)->exitOnReply((int) self::PAGE_ID, self::PSID); // "exit on reply": ang sagot ay nag-unsubscribe

        $reply = $this->heldOrder([4 => 'Nowhere po ako']);

        $this->assertSame($first->macro_output_id, $reply->macro_output_id);
        $this->assertSame([[$seq->id, self::PSID, 'unsubscribed', 'order-encode']], $this->subscriptions());
        $this->assertContains('address question already asked for this row: left for staff', $reply->input['live_notes']);
    }

    public function test_S_136_35_36_a_reply_that_completes_the_address_proceeds_without_another_astra_or_question(): void
    {
        $seq = $this->addressSequence();
        $first = $this->heldOrder();
        $this->astra(code: 'Barangay');
        $this->runAstra($first);
        Queue::assertPushed(RunSpotAstra::class, 1);

        $this->liveOrder($this->details(), [4 => 'Bungad po']);

        $this->assertSame('PROCEED', MacroOutput::sole()->STATUS);
        Queue::assertPushed(RunSpotAstra::class, 1);
        $this->assertSame(1, ChatbotSequenceSubscription::where('sequence_id', $seq->id)->count());
    }

    public function test_S_136_35_a_reply_that_is_still_held_queues_astra_again(): void
    {
        $first = $this->heldOrder();
        $reply = $this->heldOrder([4 => 'Nowhere talaga']);

        $this->assertSame($first->macro_output_id, $reply->macro_output_id);
        Queue::assertPushed(RunSpotAstra::class, 2);
    }
}
