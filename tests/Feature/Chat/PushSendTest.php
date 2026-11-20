<?php

namespace Tests\Feature\Chat;

use App\Jobs\SendChatPush;
use App\Models\ChatMember;
use App\Services\Chat\AiPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

/** WP-2: a push for each new message to every other member's devices, with the rules for who gets nothing. */
class PushSendTest extends PushTestCase
{
    public function test_a_message_pushes_to_every_other_members_devices_and_not_the_senders(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Packing', 'Ben Reyes');
        $cy = $this->person('Packing', 'Cy Lim');
        $c = $this->group($ana, [$ben, $cy], 'Packing team');
        $this->subscribe($ana, $anaPhone = $this->device('ana-phone'));
        $this->subscribe($ben, $benPhone = $this->device('ben-phone'));
        $this->subscribe($ben, $benPc = $this->device('ben-pc', 'updates.push.services.mozilla.com', '/wpush/v2/'));
        $this->subscribe($cy, $cyPhone = $this->device('cy-phone', 'web.push.apple.com', '/'));

        $id = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'Pickup at 3'])->assertCreated()->json('message.id');

        $this->assertEqualsCanonicalizing([$benPhone['endpoint'], $benPc['endpoint'], $cyPhone['endpoint']], $this->sender->endpoints());
        $this->assertSame([], $this->sender->to($anaPhone['endpoint']));
        $p = $this->sender->to($benPhone['endpoint'])[0];
        $this->assertSame('Ana Cruz in Packing team', $p['title']);
        $this->assertSame('Pickup at 3', $p['body']);
        $this->assertSame("/chat?c={$c->id}", $p['url']);
        $this->assertSame("likha-chat-{$c->id}", $p['tag']);
        $this->assertSame($c->id, $p['c']);
        $this->assertSame($id, $p['id']);
        $this->assertSame(1, $p['unread']);
        // The push service keeps only the newest undelivered push per conversation.
        $this->assertSame("c{$c->id}", $this->sender->sent[0]['topic']);
        // Each device gets its own keys.
        $sent = collect($this->sender->sent)->keyBy('endpoint');
        $this->assertSame($benPc['keys']['auth'], $sent[$benPc['endpoint']]['auth']);
        $this->assertSame($benPc['keys']['p256dh'], $sent[$benPc['endpoint']]['p256dh']);
    }

    public function test_a_direct_message_is_titled_with_the_sender_and_the_body_stops_at_80_characters(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $phone = $this->device('ben'));

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => str_repeat('word ', 40)])->assertCreated();

        $p = $this->sender->to($phone['endpoint'])[0];
        $this->assertSame('Ana Cruz', $p['title']);
        $this->assertSame(80, mb_strlen($p['body']));
        $this->assertStringEndsWith('…', $p['body']);
    }

    public function test_attachments_say_sent_a_photo_a_voice_message_or_a_file(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $phone = $this->device('ben'));

        $this->message($c, $ana, '', ['body' => null, 'attachments' => [['path' => 'chat/x.png', 'name' => 'x.png', 'size' => 1, 'kind' => 'image']]]);
        $this->message($c, $ana, '', ['body' => null, 'attachments' => [['path' => 'chat/x.webm', 'name' => 'Voice message', 'size' => 1, 'kind' => 'audio', 'duration' => 3]]]);
        $this->message($c, $ana, '', ['body' => null, 'attachments' => [['path' => 'chat/x.pdf', 'name' => 'x.pdf', 'size' => 1, 'kind' => 'file']]]);
        $this->actingAs($ana)->post("/chat/{$c->id}/messages", ['files' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['Sent a photo', 'Sent a voice message', 'Sent a file', 'Sent a photo'], array_column($this->sender->to($phone['endpoint']), 'body'));
    }

    public function test_mira_pushes_with_mira_as_the_sender(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $this->subscribe($ana, $phone = $this->device('ana'));

        $m = AiPost::post('mira', $ana->id, 'Did the 3 PM pickup go out?');

        $p = $this->sender->to($phone['endpoint'])[0];
        $this->assertSame('Mira', $p['title']);
        $this->assertSame('Did the 3 PM pickup go out?', $p['body']);
        $this->assertSame("/chat?c={$m->conversation_id}", $p['url']);
    }

    public function test_no_push_for_a_muted_conversation_until_the_mute_ends(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $phone = $this->device('ben'));
        $this->actingAs($ben)->postJson("/chat/{$c->id}/mute", ['mute' => '8h'])->assertOk();

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'quiet'])->assertCreated();
        $this->assertSame([], $this->sender->sent);

        $this->travel(9)->hours();
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'loud'])->assertCreated();
        $this->assertSame(['loud'], array_column($this->sender->to($phone['endpoint']), 'body'));
    }

    public function test_no_push_for_a_removed_or_edited_message(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $this->device('ben'));
        $id = $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'first'])->json('message.id');
        $this->assertCount(1, $this->sender->sent);

        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/{$id}", ['body' => 'first, edited'])->assertOk();
        $this->actingAs($ana)->deleteJson("/chat/{$c->id}/messages/{$id}")->assertOk();
        $this->assertCount(1, $this->sender->sent);
    }

    public function test_a_message_removed_before_the_job_runs_is_not_pushed(): void
    {
        Queue::fake();
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $this->device('ben'));
        $m = $this->message($c, $ana, 'oops');
        Queue::assertPushed(SendChatPush::class, 1);

        $m->delete();
        (new SendChatPush($m->id))->handle(app(\App\Services\Chat\PushSender::class));
        $this->assertSame([], $this->sender->sent);
    }

    public function test_system_lines_and_people_who_left_get_nothing(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $cy = $this->person();
        $c = $this->group($ana, [$ben, $cy]);
        $this->subscribe($ben, $benPhone = $this->device('ben'));
        $this->subscribe($cy, $this->device('cy'));
        ChatMember::where('conversation_id', $c->id)->where('user_id', $cy->id)->update(['left_at' => now()]);

        $this->message($c, null, 'Cy left the group');
        $this->assertSame([], $this->sender->sent);

        $this->message($c, $ana, 'hi');
        $this->assertSame([$benPhone['endpoint']], $this->sender->endpoints());
    }

    public function test_the_owner_gets_nothing_from_a_conversation_he_is_not_in_but_does_from_his_own(): void
    {
        $boss = $this->ceo();
        $ana = $this->person();
        $ben = $this->person();
        $theirs = $this->direct($ana, $ben);
        $mine = $this->direct($boss, $ana);
        $this->subscribe($boss, $bossPhone = $this->device('boss'));

        $this->actingAs($boss)->getJson("/chat/{$theirs->id}")->assertOk(); // he reads it
        $this->message($theirs, $ana, 'between us');
        $this->assertSame([], $this->sender->to($bossPhone['endpoint']));

        $this->message($mine, $ana, 'for the boss');
        $this->assertSame(['for the boss'], array_column($this->sender->to($bossPhone['endpoint']), 'body'));
    }

    public function test_a_deactivated_user_gets_no_pushes(): void
    {
        $ana = $this->person();
        $gone = $this->person('Packing', 'Old Staff', 'Resigned');
        $c = $this->direct($ana, $gone);
        $this->subscribe($gone, $this->device('gone'));

        $this->message($c, $ana, 'still there?');
        $this->assertSame([], $this->sender->sent);
    }

    public function test_posting_is_never_held_up_by_the_push(): void
    {
        Queue::fake();
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $this->device('ben'));

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'hi'])->assertCreated();
        // Only queued: nothing was sent while posting.
        Queue::assertPushed(SendChatPush::class, 1);
        $this->assertSame([], $this->sender->sent);
    }

    public function test_nobody_with_a_device_means_no_job_work_and_no_error(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'hi'])->assertCreated();
        $this->assertSame([], $this->sender->sent);
    }

    public function test_three_follow_ups_share_one_tag_so_they_show_as_one_alert(): void
    {
        $ana = $this->person();
        $this->subscribe($ana, $phone = $this->device('ana'));
        foreach (['one', 'two', 'three'] as $t) {
            AiPost::post('mira', $ana->id, $t);
        }
        $payloads = $this->sender->to($phone['endpoint']);
        $this->assertCount(3, $payloads);
        $this->assertCount(1, array_unique(array_column($payloads, 'tag')));
        $this->assertSame([1, 2, 3], array_column($payloads, 'unread'));
    }
}
