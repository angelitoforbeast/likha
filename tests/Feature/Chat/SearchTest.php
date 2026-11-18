<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;

/** MP-6: search your own conversations by name and by message text. */
class SearchTest extends ChatTestCase
{
    public function test_search_finds_chats_by_name_and_messages_by_text_in_your_conversations_only(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $oic = $this->person('Marketing - OIC', 'Olive Ortiz');
        $d = $this->direct($ana, $ben);
        $g = $this->group($oic, [$ana], 'Waybill team');
        $theirs = $this->group($oic, [$ben], 'Secret waybill club');
        $hit = $this->message($d, $ben, 'The waybill for Cebu is ready');
        $this->message($d, $ben, 'Unrelated');
        $removed = $this->message($g, $oic, 'old waybill note');
        $removed->delete();
        $this->message($theirs, $ben, 'waybill talk you cannot see');

        $res = $this->actingAs($ana)->getJson('/chat/search?q=WAYBILL')->assertOk()->json();

        $this->assertSame([$g->id], array_column($res['chats'], 'id'), 'by name: only my own chats');
        $this->assertSame([$hit->id], array_column($res['messages'], 'id'), 'by text: not removed, not other people’s chats');
        $this->assertSame($d->id, $res['messages'][0]['conversation_id']);
        $this->assertSame('Ben Reyes', $res['messages'][0]['title']);
        $this->assertSame('Ben Reyes', $res['messages'][0]['sender']);
        $this->assertStringContainsString('waybill for Cebu', $res['messages'][0]['text']);

        $byPerson = $this->actingAs($ana)->getJson('/chat/search?q=reyes')->json('chats');
        $this->assertSame([$d->id], array_column($byPerson, 'id'));
    }

    public function test_a_match_far_into_a_long_message_is_shown_in_the_snippet(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->message($c, $ben, str_repeat('Long update about the day. ', 12) . 'The rider is Juan.');

        $text = $this->actingAs($ana)->getJson('/chat/search?q=rider')->json('messages.0.text');
        $this->assertStringContainsString('The rider is Juan.', $text);
        $this->assertStringStartsWith('…', $text);
        $this->assertLessThanOrEqual(140, mb_strlen($text));
    }

    public function test_the_ceo_searches_only_conversations_she_is_in(): void
    {
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()], 'Ops');
        $this->message($g, $oic, 'invoice numbers');

        $res = $this->actingAs($this->ceo())->getJson('/chat/search?q=invoice')->assertOk()->json();
        $this->assertSame([], $res['messages']);
    }

    public function test_a_departed_member_finds_only_what_was_said_before_leaving(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ana = $this->person();
        $g = $this->group($oic, [$ana], 'Ops');
        $before = $this->message($g, $oic, 'rider schedule A');
        $this->travel(1)->minutes();
        ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ana->id])->update(['left_at' => now()]);
        $this->travel(1)->minutes();
        $this->message($g, $oic, 'rider schedule B');

        $ids = array_column($this->actingAs($ana)->getJson('/chat/search?q=rider')->json('messages'), 'id');
        $this->assertSame([$before->id], $ids);
    }

    public function test_wildcards_are_literal_and_short_or_missing_queries_give_nothing(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->message($c, $ben, 'plain text');
        $pct = $this->message($c, $ben, '50% off');

        $this->assertSame([$pct->id], array_column($this->actingAs($ana)->getJson('/chat/search?q=' . urlencode('0%'))->json('messages'), 'id'));
        $this->assertSame([], $this->actingAs($ana)->getJson('/chat/search?q=__')->json('messages'));
        $this->assertSame(['chats' => [], 'messages' => []], $this->actingAs($ana)->getJson('/chat/search?q=a')->json());
        $this->assertSame(['chats' => [], 'messages' => []], $this->actingAs($ana)->getJson('/chat/search')->json());
    }
}
