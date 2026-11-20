<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\AiPost;

/**
 * PM-1 (spec 309): sa conversation ni Mira, "AI marketing head" ang subtitle ng thread header para sa tao. Galing ito
 * sa server (conversation.subtitle), hindi sa pagtutugma ng title sa JS. Ang CEO na nagbabasa at ang ibang direct
 * chat ay walang subtitle mula sa server, kaya ang dati pa rin (active, role, "Direct chat"). Ang list row ay pareho.
 */
class MiraSubtitleTest extends ChatTestCase
{
    public function test_the_person_sees_mira_as_the_ai_marketing_head(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        $m = AiPost::post('mira', $joy->id, 'Hi Joy, please post the stories by 5:00 PM.');

        $conv = $this->actingAs($joy)->getJson("/chat/{$m->conversation_id}")->assertOk()->json('conversation');
        $this->assertSame('Mira', $conv['title']);
        $this->assertSame('AI marketing head', $conv['subtitle']);
    }

    public function test_the_owner_reading_it_gets_no_subtitle_from_the_server(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        $m = AiPost::post('mira', $joy->id, 'Hi Joy');

        $conv = $this->actingAs($this->ceo())->getJson("/chat/{$m->conversation_id}")->assertOk()->json('conversation');
        $this->assertSame('Mira and Joy Ramos', $conv['title']);
        $this->assertNull($conv['subtitle']);
    }

    public function test_a_direct_chat_between_two_people_gets_no_subtitle_from_the_server(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Data Encoder', 'Ben Reyes');
        $c = $this->direct($ana, $ben);

        $this->assertNull($this->actingAs($ana)->getJson("/chat/{$c->id}")->assertOk()->json('conversation.subtitle'));
        $this->assertNull($this->actingAs($this->ceo())->getJson("/chat/{$c->id}")->assertOk()->json('conversation.subtitle'));
        $g = $this->group($ana, [$ben]);
        $this->assertNull($this->actingAs($ana)->getJson("/chat/{$g->id}")->assertOk()->json('conversation.subtitle'));
    }

    public function test_the_list_row_is_unchanged(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        AiPost::post('mira', $joy->id, 'Hi Joy');

        $row = $this->actingAs($joy)->getJson('/chat/conversations')->assertOk()->json('conversations.0');
        $this->assertSame(['id', 'kind', 'title', 'avatar', 'last', 'at', 'unread', 'member', 'left', 'muted', 'active_at'], array_keys($row));
        $this->assertSame('Mira', $row['title']);
    }

    public function test_the_header_uses_the_server_subtitle_before_the_old_chain(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();

        $this->assertStringContainsString(
            "else sub = c.subtitle || (other && activeText(other.active_at)) || (other && other.role ? other.role : 'Direct chat');",
            $html,
        );
        $this->assertStringNotContainsString("'AI marketing head'", $html, 'no title or role text matched in JS');
    }
}
