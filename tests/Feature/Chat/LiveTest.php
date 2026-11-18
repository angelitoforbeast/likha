<?php

namespace Tests\Feature\Chat;

/** MP-5: "is typing…" through the poll, and the active dot, both from short-lived cache entries (no rows). */
class LiveTest extends ChatTestCase
{
    public function test_typing_travels_with_the_poll_and_lasts_a_few_seconds(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $rey = $this->person('Marketing', 'Rey Santos');
        $oic = $this->person('Marketing - OIC', 'Olive Ortiz');
        $g = $this->group($oic, [$ana, $rey]);

        $this->actingAs($rey)->postJson("/chat/{$g->id}/typing")->assertNoContent();
        $this->actingAs($oic)->postJson("/chat/{$g->id}/typing")->assertNoContent();

        $typing = $this->actingAs($ana)->getJson("/chat/poll?c={$g->id}&after=0")->assertOk()->json('thread.typing');
        $this->assertEqualsCanonicalizing(['Rey Santos', 'Olive Ortiz'], array_column($typing, 'name'));

        // Not yourself.
        $typing = $this->actingAs($rey)->getJson("/chat/poll?c={$g->id}&after=0")->json('thread.typing');
        $this->assertSame(['Olive Ortiz'], array_column($typing, 'name'));

        $this->travel(10)->seconds();
        $this->assertSame([], $this->actingAs($ana)->getJson("/chat/poll?c={$g->id}&after=0")->json('thread.typing'));
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_typing_is_for_active_members_only(): void
    {
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()]);

        $this->actingAs($this->ceo())->postJson("/chat/{$g->id}/typing")->assertForbidden();
        $this->actingAs($this->person())->postJson("/chat/{$g->id}/typing")->assertForbidden();
    }

    public function test_a_person_using_likha_is_active_and_it_fades(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);

        $row = fn () => collect($this->actingAs($ana)->getJson('/chat/conversations')->json('conversations'))->firstWhere('id', $c->id);
        $this->assertNull($row()['active_at']);

        // Any Likha page reads /chat/unread every 15 s while visible: that is "using Likha".
        $this->actingAs($ben)->getJson('/chat/unread')->assertOk();
        $this->assertNotNull($row()['active_at']);
        $member = collect($this->actingAs($ana)->getJson("/chat/{$c->id}")->json('conversation.members'))->firstWhere('id', $ben->id);
        $this->assertNotNull($member['active_at']);

        $this->travel(12)->minutes();
        $this->assertNotNull($row()['active_at'], 'still shown as "Active 12m ago"');

        $this->travel(2)->hours();
        $this->assertNull($row()['active_at'], 'gone after an hour');
    }
}
