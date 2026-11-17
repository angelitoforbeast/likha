<?php

namespace Tests\Feature\Chat;

/** CH-5: a message from an AI member (sender_ai) renders with the AI's name and an AI mark; no endpoint posts as AI. */
class AiMessageTest extends ChatTestCase
{
    public function test_a_seeded_ai_message_shows_the_ai_name_and_mark(): void
    {
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, []);
        $this->message($g, null, 'Ads spend is 12% over plan today.', ['sender_ai' => 'marketing_head']);
        $this->message($g, null, 'Hello', ['sender_ai' => 'new_helper']);

        $msgs = $this->actingAs($oic)->getJson("/chat/{$g->id}")->assertOk()->json('messages');

        $this->assertSame('ai', $msgs[0]['sender']['kind']);
        $this->assertSame('Marketing Head', $msgs[0]['sender']['name']);
        $this->assertSame('marketing_head', $msgs[0]['sender']['key']);
        $this->assertFalse($msgs[0]['sender']['me']);
        $this->assertSame('MH', $msgs[0]['sender']['initials']);
        $this->assertSame('New Helper', $msgs[1]['sender']['name']);

        $row = $this->actingAs($oic)->getJson('/chat/conversations')->json('conversations.0');
        $this->assertSame('New Helper', $row['last']['sender']);
        $this->assertTrue($row['last']['ai']);

        // The view draws the AI mark for sender.kind === 'ai'.
        $this->actingAs($oic)->get('/chat')->assertSee('data-ai-mark', false);
    }

    public function test_no_request_can_post_as_an_ai(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());

        $m = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'hi', 'sender_ai' => 'mira'])->assertCreated()->json('message');
        $this->assertSame('user', $m['sender']['kind']);
        $this->assertDatabaseMissing('chat_messages', ['sender_ai' => 'mira']);
    }
}
