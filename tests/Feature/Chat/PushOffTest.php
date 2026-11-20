<?php

namespace Tests\Feature\Chat;

use App\Jobs\SendChatPush;
use App\Models\User;
use App\Services\Chat\AiPost;
use Illuminate\Support\Facades\Queue;

/** Without keys, push is off: no button, nothing queued or sent, nothing fails, and the Alerts box is 300's. */
class PushOffTest extends PushTestCase
{
    public function test_no_keys_means_no_button_no_job_no_send_and_no_error(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        // A device saved while push was on stays, but nothing goes to it.
        $this->subscribe($ben, $this->device('ben'));
        $this->pushOff();
        Queue::fake();

        $html = $this->actingAs($ana)->get('/chat')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="lc-notif-allow">Turn on alerts', $html);
        $this->assertStringNotContainsString('"push_key"', $html);
        // 300's box as it was.
        $this->assertStringContainsString('id="lc-notif-allow">Allow notifications</button>', $html);
        $this->assertStringNotContainsString('id="lc-push-off"', $html);

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'hi'])->assertCreated();
        AiPost::post('mira', $ben->id, 'Follow-up');
        Queue::assertNotPushed(SendChatPush::class);
        $this->assertSame([], $this->sender->sent);
        $this->assertCount(1, (array) User::find($ben->id)->chat_push);
    }

    public function test_a_job_already_queued_when_the_keys_are_removed_sends_nothing(): void
    {
        Queue::fake();
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $this->device('ben'));
        $m = $this->message($c, $ana, 'hi');
        $this->pushOff();

        (new SendChatPush($m->id))->handle();
        $this->assertSame([], $this->sender->sent);
    }

    public function test_only_one_key_set_is_off(): void
    {
        config(['chat.push.private_key' => '']);
        $this->actingAs($this->person())->getJson('/chat/push')->assertNotFound();
        $this->assertStringNotContainsString('id="lc-notif-allow">Turn on alerts', $this->actingAs($this->person())->get('/chat')->getContent());
    }
}
