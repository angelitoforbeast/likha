<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;
use Illuminate\Http\UploadedFile;

/** MP-4: your own text message, within 15 minutes; the earlier text is kept; only the CEO sees the history. */
class EditTest extends ChatTestCase
{
    public function test_the_sender_edits_within_15_minutes_and_it_shows_edited(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ana, 'See you at 9');
        $this->travel(14)->minutes();

        $res = $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => 'See you at 10'])->assertOk()->json('message');
        $this->assertSame('See you at 10', $res['text']);
        $this->assertTrue($res['edited']);

        $fresh = $m->fresh();
        $this->assertSame('See you at 10', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
        $this->assertSame(['See you at 9'], array_column($fresh->edit_history, 'body'), 'the earlier text is kept on the server');

        $seen = collect($this->actingAs($ben)->getJson("/chat/{$c->id}")->json('messages'))->firstWhere('id', $m->id);
        $this->assertTrue($seen['edited']);
        $this->assertSame('See you at 10', $seen['text']);
    }

    public function test_after_15_minutes_it_can_no_longer_be_edited(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        $m = $this->message($c, $ana, 'Too late');
        $this->travel(16)->minutes();

        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => 'Changed'])->assertUnprocessable();
        $this->assertSame('Too late', $m->fresh()->body);
    }

    public function test_only_your_own_text_message_and_not_a_removed_one(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $his = $this->message($c, $ben, 'His words');
        $removed = $this->message($c, $ana, 'Gone');
        $removed->delete();
        $photo = $this->message($c, $ana, '', ['body' => null, 'attachments' => [[
            'path' => 'chat/2026-11-18/' . str_repeat('b', 40) . '.png', 'name' => 'a.png', 'size' => 3, 'mime' => 'image/png', 'kind' => 'image',
        ]]]);

        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$his->id}", ['body' => 'Mine now'])->assertForbidden();
        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$removed->id}", ['body' => 'Back'])->assertUnprocessable();
        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$photo->id}", ['body' => 'Caption'])->assertUnprocessable();
        $this->assertNull($photo->fresh()->body);
    }

    public function test_edited_text_has_the_same_limits_and_escaping_as_sending_and_attachments_stay(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'With a photo', 'files' => [UploadedFile::fake()->image('p.png')]])->assertCreated();
        $m = \App\Models\ChatMessage::latest('id')->first();
        $files = $m->attachments;

        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => ''])->assertUnprocessable();
        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => str_repeat('a', 4001)])->assertUnprocessable();
        $res = $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => '<script>alert(1)</script> https://example.com'])->assertOk()->json('message');

        $this->assertStringNotContainsString('<script>', $res['html']);
        $this->assertStringContainsString('&lt;script&gt;', $res['html']);
        $this->assertStringContainsString('<a href="https://example.com"', $res['html']);
        $this->assertSame($files, $m->fresh()->attachments, 'attachments do not change');
        $this->assertCount(1, $res['files']);
    }

    public function test_the_ceo_sees_the_history_and_members_do_not(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ana = $this->person();
        $g = $this->group($oic, [$ana]);
        $m = $this->message($g, $ana, 'First');
        $this->actingAs($ana)->patchJson("/chat/{$g->id}/messages/{$m->id}", ['body' => 'Second'])->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($ana)->patchJson("/chat/{$g->id}/messages/{$m->id}", ['body' => 'Third'])->assertOk();

        foreach ([$oic, $ana] as $member) {
            $msg = collect($this->actingAs($member)->getJson("/chat/{$g->id}")->json('messages'))->firstWhere('id', $m->id);
            $this->assertArrayNotHasKey('history', $msg);
            $this->assertStringNotContainsString('First', json_encode($msg));
        }

        $msg = collect($this->actingAs($this->ceo())->getJson("/chat/{$g->id}")->json('messages'))->firstWhere('id', $m->id);
        $this->assertSame(['First', 'Second'], array_column($msg['history'], 'text'));
        $this->assertSame('Third', $msg['text']);
    }

    public function test_a_departed_member_cannot_edit(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ana = $this->person();
        $g = $this->group($oic, [$ana]);
        $m = $this->message($g, $ana, 'Bye');
        ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ana->id])->update(['left_at' => now()]);

        $this->actingAs($ana)->patchJson("/chat/{$g->id}/messages/{$m->id}", ['body' => 'Changed'])->assertForbidden();
    }

    public function test_an_edit_is_sent_to_others_through_the_poll(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $m = $this->message($c, $ana, 'Typo hree');
        $this->travel(5)->seconds();
        $since = now()->toIso8601String();
        $this->travel(2)->seconds();
        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$m->id}", ['body' => 'Typo here'])->assertOk();

        $changed = $this->actingAs($ben)->getJson("/chat/poll?c={$c->id}&after={$m->id}&since=" . urlencode($since))->json('thread.changed');
        $this->assertSame([$m->id], array_column($changed, 'id'));
        $this->assertSame('Typo here', $changed[0]['text']);
        $this->assertTrue($changed[0]['edited']);
    }
}
