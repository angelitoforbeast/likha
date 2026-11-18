<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;

/** MP-3: one reaction per person per message from six allowlisted emoji; members only, the CEO after joining. */
class ReactionsTest extends ChatTestCase
{
    public function test_a_member_reacts_changes_and_removes_with_the_same_emoji(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ben, 'Shipped');

        $res = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertOk()->json('message');
        $this->assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true, 'people' => [['id' => $ana->id, 'name' => 'Ana Cruz']]]], $res['reactions']);

        // Another one replaces it: still one per person.
        $res = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => '❤️'])->assertOk()->json('message');
        $this->assertSame(['❤️'], array_column($res['reactions'], 'emoji'));
        $this->assertSame([(string) $ana->id => '❤️'], $m->fresh()->reactions);

        // The same one again removes it.
        $res = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => '❤️'])->assertOk()->json('message');
        $this->assertSame([], $res['reactions']);
        $this->assertSame([], $m->fresh()->reactions ?? []);
    }

    public function test_reactions_show_counts_and_who_reacted_to_everyone(): void
    {
        $oic = $this->person('Marketing - OIC', 'Olive Ortiz');
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $g = $this->group($oic, [$ana, $ben]);
        $m = $this->message($g, $oic, 'Target hit');
        $this->actingAs($ana)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '😆'])->assertOk();
        $this->actingAs($ben)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '😆'])->assertOk();
        $this->actingAs($oic)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertOk();

        $msg = collect($this->actingAs($ben)->getJson("/chat/{$g->id}")->assertOk()->json('messages'))->firstWhere('id', $m->id);
        // In the allowlist's order: 👍 ❤️ 😆 😮 😢 😡.
        $this->assertSame(['👍', '😆'], array_column($msg['reactions'], 'emoji'));
        $this->assertSame([1, 2], array_column($msg['reactions'], 'count'));
        $this->assertSame([false, true], array_column($msg['reactions'], 'mine'));
        $this->assertEqualsCanonicalizing(['Ana Cruz', 'Ben Reyes'], array_column($msg['reactions'][1]['people'], 'name'));
    }

    public function test_only_the_six_emoji_are_allowed(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ben);

        foreach (['🔥', '<b>x</b>', '', 'like', '👍👍', ['👍']] as $bad) {
            $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => $bad])->assertUnprocessable();
        }
        foreach (['👍', '❤️', '😆', '😮', '😢', '😡'] as $ok) {
            $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => $ok])->assertOk();
        }
        $this->assertSame([(string) $ana->id => '😡'], $m->fresh()->reactions);
    }

    public function test_no_reaction_on_removed_or_system_messages_or_from_another_conversation(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $other = $this->direct($ana, $this->person());
        $removed = $this->message($c, $ben, 'gone');
        $removed->delete();
        $system = $this->message($c, null, 'Ana created the group');
        $elsewhere = $this->message($other, $ana, 'not here');

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$removed->id}/reactions", ['emoji' => '👍'])->assertUnprocessable();
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$system->id}/reactions", ['emoji' => '👍'])->assertUnprocessable();
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$elsewhere->id}/reactions", ['emoji' => '👍'])->assertNotFound();
    }

    public function test_the_ceo_reacts_only_after_joining_and_a_departed_member_cannot(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ana = $this->person();
        $g = $this->group($oic, [$ana]);
        $m = $this->message($g, $oic, 'Hello');
        $ceo = $this->ceo();

        $this->actingAs($ceo)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($ceo)->postJson("/chat/{$g->id}/join")->assertOk();
        $this->actingAs($ceo)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertOk();

        ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ana->id])->update(['left_at' => now()]);
        $this->actingAs($ana)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertForbidden();
    }

    public function test_a_reaction_is_sent_to_others_through_the_poll(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ben, 'Old message');
        $this->travel(5)->seconds();
        $since = now()->toIso8601String();
        $this->travel(2)->seconds();
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/{$m->id}/reactions", ['emoji' => '😮'])->assertOk();

        $thread = $this->actingAs($ben)->getJson("/chat/poll?c={$c->id}&after={$m->id}&since=" . urlencode($since))->assertOk()->json('thread');
        $this->assertSame([], $thread['messages']);
        $this->assertSame([$m->id], array_column($thread['changed'], 'id'));
        $this->assertSame('😮', $thread['changed'][0]['reactions'][0]['emoji']);
        $this->assertFalse($thread['changed'][0]['reactions'][0]['mine']);
    }
}
