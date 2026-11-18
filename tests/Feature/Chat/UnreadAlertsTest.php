<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;
use Illuminate\Support\Facades\Blade;

/** MP-1, MP-2, MP-6 (mute): the unread count every Likha page reads, the new-message alerts, and muting. */
class UnreadAlertsTest extends ChatTestCase
{
    public function test_unread_without_alerts_keeps_its_296_shape(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        $this->message($c, $c->members()->where('user_id', '!=', $ana->id)->first()->user, 'hi');

        $this->actingAs($ana)->getJson('/chat/unread')->assertOk()->assertExactJson(['unread' => 1]);
    }

    public function test_a_muted_conversation_does_not_count_until_the_mute_ends(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $loud = $this->direct($ana, $ben);
        $quiet = $this->group($ben, [$ana]);
        $this->message($loud, $ben, 'one');
        $this->message($quiet, $ben, 'two');
        $this->message($quiet, $ben, 'three');
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 3]);

        $res = $this->actingAs($ana)->postJson("/chat/{$quiet->id}/mute", ['mute' => '8h'])->assertOk()->json();
        $this->assertNotNull($res['muted_until']);
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 1]);

        // The list still shows the row's own count, marked muted.
        $row = collect($this->actingAs($ana)->getJson('/chat/conversations')->json('conversations'))->firstWhere('id', $quiet->id);
        $this->assertTrue($row['muted']);
        $this->assertSame(2, $row['unread']);
        $this->assertSame(1, $this->actingAs($ana)->getJson('/chat/conversations')->json('unread'));

        $this->travel(8)->hours();
        $this->travel(1)->minutes();
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 3]);
    }

    public function test_mute_until_turned_back_on_and_unmute(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->message($c, $ben, 'one');

        $this->actingAs($ana)->postJson("/chat/{$c->id}/mute", ['mute' => 'on'])->assertOk();
        $this->travel(400)->days();
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 0]);
        $this->assertTrue($this->actingAs($ana)->getJson("/chat/{$c->id}")->json('conversation.me.muted'));

        $res = $this->actingAs($ana)->postJson("/chat/{$c->id}/mute", ['mute' => 'off'])->assertOk()->json();
        $this->assertNull($res['muted_until']);
        $this->message($c, $ben, 'two');
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 1]);

        // Muting is mine only: Ben's count is untouched.
        $this->actingAs($ana)->postJson("/chat/{$c->id}/mute", ['mute' => 'on'])->assertOk();
        $this->message($c, $ana, 'to ben');
        $this->actingAs($ben)->getJson('/chat/unread')->assertExactJson(['unread' => 1]);
        $this->assertNull(ChatMember::where(['conversation_id' => $c->id, 'user_id' => $ben->id])->value('muted_until'));
    }

    public function test_bad_mute_values_are_refused(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        foreach (['', 'forever', '1h', null] as $bad) {
            $this->actingAs($ana)->postJson("/chat/{$c->id}/mute", ['mute' => $bad])->assertUnprocessable();
        }
    }

    public function test_alerts_start_from_a_baseline_and_bring_only_new_unread_messages_from_others(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $oic = $this->person('Marketing - OIC', 'Olive Ortiz');
        $d = $this->direct($ana, $ben);
        $g = $this->group($oic, [$ana, $ben], 'Packing team');
        $old = $this->message($d, $ben, 'old news');

        // First call of a page: no alert for what was there before, only the baseline.
        $res = $this->actingAs($ana)->getJson('/chat/unread?alerts=1')->assertOk()->json();
        $this->assertSame(1, $res['unread']);
        $this->assertSame($old->id, $res['last_id']);
        $this->assertSame([], $res['alerts']);

        $mine = $this->message($d, $ana, 'my own');
        $long = $this->message($g, $oic, str_repeat('Packing list for today. ', 10));
        $muted = $this->direct($ana, $this->person('Marketing', 'Muted Person'));
        ChatMember::where(['conversation_id' => $muted->id, 'user_id' => $ana->id])->update(['muted_until' => now()->addHours(8)]);
        $this->message($muted, $muted->members()->where('user_id', '!=', $ana->id)->first()->user, 'shh');
        $read = $this->message($d, $ben, 'already seen');
        ChatMember::where(['conversation_id' => $d->id, 'user_id' => $ana->id])->update(['last_read_message_id' => $read->id]);
        $fresh = $this->message($d, $ben, 'New one');

        $res = $this->actingAs($ana)->getJson('/chat/unread?alerts=1&after=' . $old->id)->assertOk()->json();
        $this->assertSame([$fresh->id, $long->id], array_column($res['alerts'], 'id'), 'newest first; not own, muted or read');
        $this->assertSame($fresh->id, $res['last_id']);

        [$a, $b] = $res['alerts'];
        $this->assertSame(['id' => $fresh->id, 'conversation_id' => $d->id, 'title' => 'Ben Reyes', 'group' => false, 'sender' => 'Ben Reyes', 'text' => 'New one', 'url' => "/chat?c={$d->id}"], $a);
        $this->assertSame('Packing team', $b['title']);
        $this->assertTrue($b['group']);
        $this->assertSame('Olive Ortiz', $b['sender']);
        $this->assertLessThanOrEqual(80, mb_strlen($b['text']), 'the first 80 characters');
        $this->assertStringStartsWith('Packing list for today.', $b['text']);

        $this->assertSame([], $this->actingAs($ana)->getJson('/chat/unread?alerts=1&after=' . $fresh->id)->json('alerts'));
        $this->assertNotSame($mine->id, $res['alerts'][0]['id']);
    }

    public function test_alert_text_for_photos_and_voice(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->message($c, $ben, '', ['body' => null, 'attachments' => [['path' => 'chat/x/' . str_repeat('c', 40) . '.png', 'name' => 'a.png', 'size' => 1, 'mime' => 'image/png', 'kind' => 'image']]]);
        $this->message($c, $ben, '', ['body' => null, 'attachments' => [['path' => 'chat/x/' . str_repeat('d', 40) . '.webm', 'name' => 'Voice message', 'size' => 1, 'mime' => 'audio/webm', 'kind' => 'audio', 'duration' => 4]]]);

        $alerts = $this->actingAs($ana)->getJson('/chat/unread?alerts=1&after=0')->json('alerts');
        $this->assertSame(['Voice message', 'Sent a photo'], array_column($alerts, 'text'));
    }

    public function test_bad_alert_parameters_are_refused_and_guests_get_401(): void
    {
        $this->getJson('/chat/unread?alerts=1')->assertUnauthorized();
        $this->actingAs($this->person())->getJson('/chat/unread?alerts=1&after=-1')->assertUnprocessable();
        $this->actingAs($this->person())->getJson('/chat/unread?alerts=1&after=abc')->assertUnprocessable();
    }

    public function test_every_signed_in_page_polls_unread_every_15_seconds_and_a_guest_page_does_not(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();
        $this->assertStringContainsString('data-chat-unread', $html);
        $this->assertStringContainsString('"poll_ms":15000', $html);
        $this->assertStringContainsString('/chat/unread', $html);
        $this->assertStringContainsString('visibilitychange', $html);
        // The badge goes on the sidebar's Chat link, and the count into the tab title.
        $this->assertStringContainsString('data-nav-link="/chat"', $html);
        $this->assertStringContainsString('document.title', $html);
        // Never asks for notifications on load: only on the chat page's button.
        $this->assertStringNotContainsString('requestPermission()', $this->between($html, 'data-chat-unread', '</script>'));

        $other = Blade::render('<x-layout><p>Other page</p></x-layout>');
        $this->assertStringContainsString('data-chat-unread', $other, 'on every <x-layout> page');

        auth()->logout();
        $guest = Blade::render('<x-layout><p>Guest page</p></x-layout>');
        $this->assertStringContainsString('Guest page', $guest);
        $this->assertStringNotContainsString('data-chat-unread', $guest);
        $this->assertStringNotContainsString('/chat/unread', $guest);
    }

    public function test_the_chat_page_has_the_allow_notifications_button_and_the_sound_switch(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();
        $this->assertStringContainsString('Allow notifications', $html);
        $this->assertStringContainsString('id="lc-sound"', $html);
        $this->assertStringContainsString('requestPermission', $html);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }
}
