<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Services\Chat\AiPost;

/**
 * MC-3 (spec 299): ang conversation ni Mira at ng isang tao. direct_key "ai:mira:<user_id>", title "Mira", iisang
 * miyembro (ang tao); si Mira ay sender_ai, hindi miyembro. Ang tao at ang CEO lang ang nakakabasa; 403 sa iba.
 */
class MiraConversationTest extends ChatTestCase
{
    public function test_one_conversation_per_person_keyed_by_ai_and_user_and_reused(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        $ben = $this->person('Marketing', 'Ben Cruz');

        $a = AiPost::post('mira', $joy->id, 'First');
        $b = AiPost::post('mira', $joy->id, 'Second');
        $c = AiPost::post('mira', $ben->id, 'Third');

        $this->assertSame($a->conversation_id, $b->conversation_id);
        $this->assertNotSame($a->conversation_id, $c->conversation_id);
        $conv = ChatConversation::find($a->conversation_id);
        $this->assertSame('ai:mira:' . $joy->id, $conv->direct_key);
        $this->assertSame(ChatConversation::DIRECT, $conv->kind);
        $this->assertSame('Mira', $conv->title);
        $this->assertNull($conv->created_by);
        $this->assertSame([$joy->id], ChatMember::where('conversation_id', $conv->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue(AiPost::isAiConversation($conv));
        $this->assertFalse(AiPost::isAiConversation($this->direct($joy, $ben)));
        $this->assertSame('mira', $a->sender_ai);
        $this->assertNull($a->sender_user_id);
        $this->assertSame($b->created_at->toDateTimeString(), $conv->fresh()->last_message_at->toDateTimeString());
    }

    public function test_an_existing_conversation_made_by_a_racing_request_is_reused(): void
    {
        $joy = $this->person();
        $first = AiPost::conversation('mira', $joy->id);
        $this->assertSame($first->id, AiPost::conversation('mira', $joy->id)->id);
        $this->assertSame(1, ChatConversation::count());
        $this->assertSame(1, ChatMember::count());
    }

    public function test_empty_text_is_never_posted(): void
    {
        $joy = $this->person();
        $this->expectException(\InvalidArgumentException::class);
        try {
            AiPost::post('mira', $joy->id, '   ');
        } finally {
            $this->assertSame(0, ChatMessage::count());
        }
    }

    public function test_the_person_sees_it_titled_mira_with_the_ai_avatar_and_unread(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        $m = AiPost::post('mira', $joy->id, 'Hi Joy, please post the stories by 5:00 PM.');

        $row = $this->actingAs($joy)->getJson('/chat/conversations')->assertOk()->json('conversations.0');
        $this->assertSame('Mira', $row['title']);
        $this->assertTrue($row['avatar']['ai']);
        $this->assertSame('M', $row['avatar']['initials']);
        $this->assertSame('Mira', $row['last']['sender']);
        $this->assertTrue($row['last']['ai']);
        $this->assertSame(1, $row['unread']);

        $thread = $this->actingAs($joy)->getJson("/chat/{$m->conversation_id}")->assertOk()->json();
        $this->assertSame('Mira', $thread['conversation']['title']);
        $this->assertTrue($thread['conversation']['me']['can_post']);
        $this->assertFalse($thread['conversation']['me']['can_leave']);
        $this->assertFalse($thread['conversation']['me']['can_manage']);
        $this->assertSame('ai', $thread['messages'][0]['sender']['kind']);
        $this->assertSame('Mira', $thread['messages'][0]['sender']['name']);
    }

    public function test_the_person_can_reply_and_the_reply_is_kept_and_mira_does_not_answer(): void
    {
        $joy = $this->person();
        $m = AiPost::post('mira', $joy->id, 'Hi Joy');

        $this->actingAs($joy)->postJson("/chat/{$m->conversation_id}/messages", ['body' => 'On it po.'])->assertCreated();

        $this->assertSame(['Hi Joy', 'On it po.'], ChatMessage::orderBy('id')->pluck('body')->all());
        $this->assertSame(1, ChatMessage::whereNotNull('sender_ai')->count());
    }

    public function test_the_ceo_reads_it_and_the_replies_without_joining(): void
    {
        $ceo = $this->ceo();
        $joy = $this->person('Marketing', 'Joy Ramos');
        $m = AiPost::post('mira', $joy->id, 'Hi Joy');
        $this->message(ChatConversation::find($m->conversation_id), $joy, 'On it po.');

        $thread = $this->actingAs($ceo)->getJson("/chat/{$m->conversation_id}")->assertOk()->json();
        $this->assertSame(['Hi Joy', 'On it po.'], array_column($thread['messages'], 'text'));
        $this->assertSame('Mira and Joy Ramos', $thread['conversation']['title']);
        $this->assertFalse($thread['conversation']['me']['can_post']);
        $this->assertFalse($thread['conversation']['me']['can_join']);

        $rows = $this->actingAs($ceo)->getJson('/chat/conversations?all=1')->json('conversations');
        $this->assertSame('Mira and Joy Ramos', collect($rows)->firstWhere('id', $m->conversation_id)['title']);
        // hindi siya miyembro: hindi lumalabas sa sarili niyang listahan
        $this->assertSame([], $this->actingAs($ceo)->getJson('/chat/conversations')->json('conversations'));
        $this->actingAs($ceo)->postJson("/chat/{$m->conversation_id}/messages", ['body' => 'Hi'])->assertForbidden();
    }

    public function test_a_third_person_gets_403_on_every_route(): void
    {
        $joy = $this->person();
        $oic = $this->person('Marketing - OIC', 'Oic Person');
        $ben = $this->person('Marketing', 'Ben Cruz');
        $m = AiPost::post('mira', $joy->id, 'Hi Joy');
        $id = $m->conversation_id;

        foreach ([$oic, $ben] as $other) {
            $this->actingAs($other)->getJson("/chat/{$id}")->assertForbidden();
            $this->actingAs($other)->getJson("/chat/poll?c={$id}")->assertForbidden();
            $this->actingAs($other)->postJson("/chat/{$id}/messages", ['body' => 'hi'])->assertForbidden();
            $this->actingAs($other)->postJson("/chat/{$id}/read")->assertForbidden();
            $this->actingAs($other)->deleteJson("/chat/{$id}/messages/{$m->id}")->assertForbidden();
            $this->actingAs($other)->postJson("/chat/{$id}/join")->assertForbidden();
            $this->assertSame([], $this->actingAs($other)->getJson('/chat/conversations?all=1')->json('conversations'));
        }
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_a_person_cannot_remove_miras_message(): void
    {
        $joy = $this->person();
        $m = AiPost::post('mira', $joy->id, 'Hi Joy');

        $this->actingAs($joy)->deleteJson("/chat/{$m->conversation_id}/messages/{$m->id}")->assertForbidden();
        $this->assertFalse($m->fresh()->trashed());
    }

    public function test_person_to_person_direct_chats_are_unchanged(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        $ben = $this->person('Marketing', 'Ben Cruz');
        AiPost::post('mira', $joy->id, 'Hi Joy');

        $id = $this->actingAs($joy)->postJson('/chat/direct', ['user_id' => $ben->id])->assertOk()->json('id');
        $this->assertSame(ChatConversation::directKey($joy->id, $ben->id), ChatConversation::find($id)->direct_key);
        $titles = collect($this->actingAs($joy)->getJson('/chat/conversations')->json('conversations'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Ben Cruz', 'Mira'], $titles);
        $row = collect($this->actingAs($joy)->getJson('/chat/conversations')->json('conversations'))->firstWhere('title', 'Ben Cruz');
        $this->assertArrayNotHasKey('ai', $row['avatar']);
    }
}
