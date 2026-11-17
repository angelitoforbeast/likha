<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;

/** CH-3: polling returns only what is new, only to members; opening marks read; the unread count. */
class PollTest extends ChatTestCase
{
    public function test_poll_returns_only_messages_after_the_last_id(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m1 = $this->message($c, $ben, 'one');
        $m2 = $this->message($c, $ben, 'two');
        $m3 = $this->message($c, $ana, 'three');

        $res = $this->actingAs($ana)->getJson("/chat/poll?c={$c->id}&after={$m1->id}")->assertOk()->json();

        $this->assertSame([$m2->id, $m3->id], array_column($res['thread']['messages'], 'id'));
        $this->assertArrayHasKey('now', $res);
        $this->assertArrayHasKey('reads', $res['thread']);

        $none = $this->actingAs($ana)->getJson("/chat/poll?c={$c->id}&after={$m3->id}")->json('thread.messages');
        $this->assertSame([], $none);
    }

    public function test_poll_without_a_conversation_returns_list_rows_changed_since(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $quiet = $this->direct($ana, $ben);
        $this->message($quiet, $ben, 'old');
        $since = now()->toIso8601String();

        $this->travel(10)->seconds();
        $busy = $this->group($ben, [$ana]);
        $this->message($busy, $ben, 'new');

        $res = $this->actingAs($ana)->getJson('/chat/poll?since=' . urlencode($since))->assertOk()->json();
        $this->assertNull($res['thread']);
        $this->assertSame([$busy->id], array_column($res['conversations'], 'id'));
        $this->assertSame(1, $res['unread']);
    }

    public function test_poll_reports_messages_removed_since_the_last_poll(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ben, 'oops');
        $since = now()->toIso8601String();
        $this->actingAs($ben)->deleteJson("/chat/{$c->id}/messages/{$m->id}")->assertOk();

        $res = $this->actingAs($ana)->getJson("/chat/poll?c={$c->id}&after={$m->id}&since=" . urlencode($since))->json('thread');
        $this->assertSame([$m->id], $res['removed']);
    }

    public function test_poll_is_refused_to_non_members(): void
    {
        $c = $this->direct($this->person(), $this->person());
        $this->actingAs($this->person())->getJson("/chat/poll?c={$c->id}&after=0")->assertForbidden();
        $this->actingAs($this->person())->getJson('/chat/poll?c=99999&after=0')->assertNotFound();
    }

    public function test_bad_poll_parameters_are_refused(): void
    {
        $ana = $this->person();
        $this->actingAs($ana)->getJson('/chat/poll?since=yesterday-ish')->assertUnprocessable();
        $this->actingAs($ana)->getJson('/chat/poll?c=1&after=-4')->assertUnprocessable();
    }

    public function test_opening_and_polling_a_conversation_marks_it_read(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m1 = $this->message($c, $ben, 'one');

        $this->actingAs($ana)->getJson('/chat/unread')->assertOk()->assertExactJson(['unread' => 1]);
        $this->actingAs($ana)->getJson("/chat/{$c->id}")->assertOk();
        $this->assertSame($m1->id, (int) ChatMember::where(['conversation_id' => $c->id, 'user_id' => $ana->id])->value('last_read_message_id'));
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 0]);

        $m2 = $this->message($c, $ben, 'two');
        $this->actingAs($ana)->getJson("/chat/poll?c={$c->id}&after={$m1->id}")->assertOk();
        $this->assertSame($m2->id, (int) ChatMember::where(['conversation_id' => $c->id, 'user_id' => $ana->id])->value('last_read_message_id'));

        $m3 = $this->message($c, $ben, 'three');
        $this->actingAs($ana)->postJson("/chat/{$c->id}/read")->assertOk();
        $this->assertSame($m3->id, (int) ChatMember::where(['conversation_id' => $c->id, 'user_id' => $ana->id])->value('last_read_message_id'));
    }

    public function test_unread_counts_messages_from_others_in_active_conversations_only(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $oic = $this->person('Marketing - OIC');
        $d = $this->direct($ana, $ben);
        $this->message($d, $ben, 'a');
        $this->message($d, $ben, 'b');
        $this->message($d, $ana, 'mine');
        $removed = $this->message($d, $ben, 'gone');
        $removed->delete();
        $this->message($d, null, 'System line');

        $g = $this->group($oic, [$ana]);
        $this->message($g, $oic, 'c');
        $this->message($g, null, null, ['sender_ai' => 'mira', 'body' => 'AI note']);

        $left = $this->group($oic, [$ana]);
        ChatMember::where(['conversation_id' => $left->id, 'user_id' => $ana->id])->update(['left_at' => now()]);
        $this->message($left, $oic, 'not for you');

        // 2 in the direct chat + 1 person and 1 AI in the group; own, removed, system and left-group lines do not count.
        $this->actingAs($ana)->getJson('/chat/unread')->assertExactJson(['unread' => 4]);
    }

    public function test_the_page_polls_every_3_seconds_and_pauses_when_hidden(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();
        $this->assertStringContainsString('"poll_ms":3000', $html);
        $this->assertStringContainsString('visibilitychange', $html);
    }
}
