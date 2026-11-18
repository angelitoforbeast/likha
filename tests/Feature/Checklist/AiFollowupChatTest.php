<?php

namespace Tests\Feature\Checklist;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChecklistAiFollowup;
use App\Services\AiFollowups;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * MC-3 at MC-4 (spec 299): ang approved follow-up (ng CEO o kusa) ay ipinapadala ni Mira sa Likha Chat ng tao, sa
 * iisang conversation na "Mira". Isang beses lang (idempotent); kapag pumalya ang chat, approved pa rin at may Retry.
 */
class AiFollowupChatTest extends AiFollowupTestCase
{
    public function test_approving_posts_the_text_the_deadline_and_the_task_link_as_mira(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->followup($joy, $task, ['description' => 'Hi Joy, please post the 3 stories by 5:00 PM.']);

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();

        $m = ChatMessage::sole();
        $this->assertSame('mira', $m->sender_ai);
        $this->assertNull($m->sender_user_id);
        $this->assertStringStartsWith('Hi Joy, please post the 3 stories by 5:00 PM.', $m->body);
        $this->assertStringContainsString('Due by 5:00 PM · Post 3 stories on the main page', $m->body);
        $this->assertStringContainsString(url('/checklist') . '#task-' . $task->id, $m->body);
        $this->assertSame($m->id, (int) $f->fresh()->chat_message_id);

        $c = ChatConversation::sole();
        $this->assertSame('Mira', $c->title);
        $this->assertSame([$joy->id], ChatMember::where('conversation_id', $c->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($c->last_message_at);

        // ang link sa chat ay nagiging <a> papunta sa task
        $html = $this->actingAs($joy)->getJson("/chat/{$c->id}")->assertOk()->json('messages.0.html');
        $this->assertStringContainsString('href="' . url('/checklist') . '#task-' . $task->id . '"', $html);
    }

    public function test_edit_and_approve_sends_the_edited_text_and_deadline(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/edit", [
            'message' => 'Hi Joy, the 3 stories with prices please, by 8:00 PM. Salamat!', 'deadline' => '20:00',
        ])->assertOk();

        $body = ChatMessage::sole()->body;
        $this->assertStringStartsWith('Hi Joy, the 3 stories with prices please, by 8:00 PM. Salamat!', $body);
        $this->assertStringContainsString('Due by 8:00 PM', $body);
        $this->assertStringNotContainsString('by 5:00 PM.', $body);
    }

    public function test_rejected_and_expired_drafts_send_nothing(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/reject", ['note' => 'Not today.'])->assertOk();
        $old = $this->followup($joy, $this->lateTask($joy, ['title' => 'Yesterday']), ['follows_date' => '2026-11-17']);
        $this->runFollowups();

        $this->assertSame('expired', $old->fresh()->review_status);
        $this->assertSame(0, ChatMessage::count());
        $this->assertSame(0, ChatConversation::count());
        $this->assertFalse(AiFollowups::sendToChat($f->fresh()));
    }

    public function test_every_followup_reuses_the_persons_one_mira_conversation(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        foreach ([[$joy, 'One'], [$joy, 'Two'], [$ben, 'Three']] as [$who, $title]) {
            $f = $this->followup($who, $this->lateTask($who, ['title' => $title]));
            $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();
        }

        $this->assertSame(2, ChatConversation::count());
        $joys = ChatConversation::where('direct_key', 'ai:mira:' . $joy->id)->sole();
        $this->assertSame(2, ChatMessage::where('conversation_id', $joys->id)->count());
        $this->assertSame(1, ChatMessage::where('conversation_id', ChatConversation::where('direct_key', 'ai:mira:' . $ben->id)->value('id'))->count());
    }

    public function test_sending_is_idempotent(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();
        // pangalawang approve: 409, at walang pangalawang message
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertStatus(409);
        // ang job o ang retry na inulit
        $this->assertTrue(AiFollowups::sendToChat($f->fresh()));
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/chat")->assertOk();

        $this->assertSame(1, ChatMessage::count());
    }

    public function test_a_failed_post_keeps_the_followup_approved_and_the_retry_sends_it(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->followup($joy, $task, ['description' => 'Hi Joy, the stories please.']);
        Schema::rename('chat_messages', 'chat_messages_away');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) { $logged[] = $e; });

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();

        $f->refresh();
        $this->assertSame('approved', $f->review_status);
        $this->assertNull($f->chat_message_id);
        $this->assertNotNull($f->chat_failed_at);
        $warnings = array_filter($logged, fn (MessageLogged $e) => $e->level === 'warning' && str_contains($e->message, 'not sent to chat') && ($e->context['followup_id'] ?? null) === $f->id);
        $this->assertCount(1, $warnings);
        $this->actingAs($joy)->get('/checklist')->assertOk()->assertSee('Hi Joy, the stories please.');
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()
            ->assertSee('Not sent to chat')
            ->assertSee('data-retry-chat="' . $f->id . '"', false);

        // sira pa rin: ang retry ay nagsasabi
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/chat")->assertStatus(502)->assertJson(['ok' => false]);

        Schema::rename('chat_messages_away', 'chat_messages');
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/chat")->assertOk()->assertJson(['ok' => true]);

        $f->refresh();
        $this->assertNotNull($f->chat_message_id);
        $this->assertNull($f->chat_failed_at);
        $this->assertSame(1, ChatMessage::count());
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()
            ->assertDontSee('Not sent to chat')
            ->assertDontSee('data-retry-chat', false);
    }

    public function test_retry_is_ceo_only_and_only_for_approved_followups(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $draft = $this->followup($joy, $this->lateTask($joy));
        foreach (['Marketing - OIC', 'Marketing'] as $role) {
            $this->actingAs($this->user($role))->postJson("/checklist/ai-followups/{$draft->id}/chat")->assertForbidden();
        }
        $this->actingAs($joy)->postJson("/checklist/ai-followups/{$draft->id}/chat")->assertForbidden();
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$draft->id}/chat")->assertStatus(409);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$this->lateTask($joy, ['title' => 'Plain'])->id}/chat")->assertNotFound();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_an_expired_or_done_followup_shows_not_sent_without_a_retry_button(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->reviewed($this->followup($joy, $this->lateTask($joy), ['follows_date' => '2026-11-17']), $ceo, 'approved', ['chat_failed_at' => now()]);

        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()
            ->assertSee('Not sent to chat')
            ->assertDontSee('data-retry-chat', false);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/chat")->assertStatus(409);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_the_sent_tab_links_each_followup_in_chat_to_the_mira_conversation(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();
        // approved bago ang spec na ito: walang chat, walang link, walang "Not sent"
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $this->reviewed($this->followup($ben, $this->lateTask($ben, ['title' => 'Old one'])), $ceo, 'approved');

        $c = ChatConversation::sole();
        $html = $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()
            ->assertSee('Open chat')
            ->assertDontSee('Not sent to chat')
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-open-chat'));
        $this->assertStringContainsString('href="' . url('/chat') . '?c=' . $c->id . '"', $html);

        // ang CEO ay nakakabasa ng conversation (hindi miyembro)
        $this->actingAs($ceo)->getJson("/chat/{$c->id}")->assertOk()->assertJsonPath('messages.0.sender.name', 'Mira');
    }

    public function test_html_in_the_followup_is_escaped_in_chat(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy, ['title' => '<b>Bold</b> task']), ['description' => 'Hi <script>alert(1)</script> Joy']);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();

        $html = $this->actingAs($joy)->getJson('/chat/' . ChatConversation::sole()->id)->json('messages.0.html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_mira_conversation_is_a_followup_reply_place_not_a_checklist_item(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();
        $c = ChatConversation::sole();

        $this->actingAs($joy)->postJson("/chat/{$c->id}/messages", ['body' => 'Noted po, posting now.'])->assertCreated();

        $this->assertSame(['mira', null], ChatMessage::orderBy('id')->pluck('sender_ai')->all());
        // walang sagot si Mira
        $this->assertSame(2, ChatMessage::count());
        $this->assertSame(1, ChecklistAiFollowup::count());
    }
}
