<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;
use App\Models\ChatMessage;

/** CH-4: the CEO reads any conversation (and the page says so) but joins before posting. */
class CeoTest extends ChatTestCase
{
    public function test_the_ceo_has_an_all_conversations_filter_and_others_do_not(): void
    {
        $ceo = $this->ceo();
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->message($c, $ana, 'between us');

        $this->actingAs($ceo)->getJson('/chat/conversations')->assertJsonCount(0, 'conversations');
        $rows = $this->actingAs($ceo)->getJson('/chat/conversations?all=1')->assertOk()->json('conversations');
        $this->assertSame([$c->id], array_column($rows, 'id'));
        $this->assertFalse($rows[0]['member']);
        $this->assertSame(0, $rows[0]['unread']);

        // all=1 means nothing for anyone else.
        $this->actingAs($this->person('Marketing - OIC'))->getJson('/chat/conversations?all=1')->assertJsonCount(0, 'conversations');
        $this->actingAs($ceo)->get('/chat')->assertSee('All conversations');
        $this->actingAs($ana)->get('/chat')->assertDontSee('All conversations');
    }

    public function test_the_ceo_reads_any_conversation_without_becoming_a_member(): void
    {
        $ceo = $this->ceo();
        $ana = $this->person('Marketing', 'Ana');
        $ben = $this->person('Marketing', 'Ben');
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ana, 'between us');

        $res = $this->actingAs($ceo)->getJson("/chat/{$c->id}")->assertOk()->json();
        $this->assertSame([$m->id], array_column($res['messages'], 'id'));
        $this->assertFalse($res['conversation']['me']['member']);
        $this->assertFalse($res['conversation']['me']['can_post']);
        $this->assertSame('Ana and Ben', $res['conversation']['title']);
        $this->actingAs($ceo)->getJson("/chat/poll?c={$c->id}&after=0")->assertOk();
        $this->assertFalse(ChatMember::where('user_id', $ceo->id)->exists(), 'reading leaves no trace in membership');
    }

    public function test_the_ceo_must_join_a_group_to_post_and_members_see_the_join(): void
    {
        $ceo = $this->ceo('Owner Boss');
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()]);

        $this->actingAs($ceo)->postJson("/chat/{$g->id}/messages", ['body' => 'Hi'])->assertForbidden();
        $this->assertTrue($this->actingAs($ceo)->getJson("/chat/{$g->id}")->json('conversation.me.can_join'));

        $this->actingAs($ceo)->postJson("/chat/{$g->id}/join")->assertOk();
        $this->assertSame('admin', ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ceo->id])->value('role'));
        $this->assertTrue(ChatMessage::where('conversation_id', $g->id)->where('body', 'Owner Boss joined')->exists());
        $this->actingAs($ceo)->postJson("/chat/{$g->id}/messages", ['body' => 'Hi'])->assertCreated();

        $sys = collect($this->actingAs($oic)->getJson("/chat/{$g->id}")->json('messages'))->firstWhere('text', 'Owner Boss joined');
        $this->assertSame('system', $sys['sender']['kind']);
    }

    public function test_only_the_ceo_may_join_and_only_groups(): void
    {
        $ceo = $this->ceo();
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()]);
        $d = $this->direct($this->person(), $this->person());

        $this->actingAs($this->person('Data Encoder - OIC'))->postJson("/chat/{$g->id}/join")->assertForbidden();
        $this->actingAs($ceo)->postJson("/chat/{$d->id}/join")->assertUnprocessable();
        $this->actingAs($oic)->postJson("/chat/{$g->id}/join")->assertUnprocessable();   // already in it
    }

    public function test_every_thread_states_that_the_owner_can_read(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());

        $this->actingAs($ana)->get("/chat?c={$c->id}")->assertSee('The owner can read every conversation in Likha Chat');
    }
}
