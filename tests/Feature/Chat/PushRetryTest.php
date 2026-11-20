<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use App\Services\Chat\PushSender;
use Illuminate\Support\Facades\Log;

/** WP-1 and WP-2: a gone subscription (404 / 410) is removed on the next send; other failures retry at most twice. */
class PushRetryTest extends PushTestCase
{
    public function test_a_gone_subscription_is_removed_and_the_others_stay(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $old = $this->device('old-phone'));
        $this->subscribe($ben, $new = $this->device('new-phone'));
        $this->sender->results[$old['endpoint']] = PushSender::GONE;

        $this->message($c, $ana, 'hi');

        $left = array_column((array) User::find($ben->id)->chat_push, 'endpoint');
        $this->assertSame([$new['endpoint']], $left);
        // Gone is not retried.
        $this->assertCount(1, $this->sender->to($old['endpoint']));

        $this->message($c, $ana, 'again');
        $this->assertCount(1, $this->sender->to($old['endpoint']));
        $this->assertCount(2, $this->sender->to($new['endpoint']));
    }

    public function test_a_failed_send_is_logged_and_retried_at_most_twice_for_that_device_only(): void
    {
        Log::spy();
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $flaky = $this->device('flaky'));
        $this->subscribe($ben, $fine = $this->device('fine'));
        $this->sender->results[$flaky['endpoint']] = PushSender::FAILED;

        $this->message($c, $ana, 'hi');

        $this->assertCount(3, $this->sender->to($flaky['endpoint'])); // first try + 2 retries
        $this->assertCount(1, $this->sender->to($fine['endpoint']));
        // A failed device is kept (it may work tomorrow).
        $this->assertCount(2, (array) User::find($ben->id)->chat_push);
        Log::shouldHaveReceived('warning')->times(3)->withArgs(function ($msg, $ctx = []) use ($flaky) {
            $flat = $msg . json_encode($ctx);

            return str_contains($msg, 'chat push') && ! str_contains($flat, $flaky['keys']['auth']) && ! str_contains($flat, $flaky['keys']['p256dh']);
        });
    }

    public function test_a_retry_that_works_stops_retrying(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $flaky = $this->device('flaky'));
        $sender = new class extends FakePushSender {
            public int $calls = 0;

            public function send(array $deliveries): array
            {
                $this->calls++;
                $this->results = $this->calls === 1 ? [$deliveries[array_key_first($deliveries)]['endpoint'] => PushSender::FAILED] : [];

                return parent::send($deliveries);
            }
        };
        $this->app->instance(PushSender::class, $sender);

        $this->message($c, $ana, 'hi');
        $this->assertSame(2, $sender->calls);
    }

    public function test_a_sender_that_throws_does_not_break_posting(): void
    {
        $this->app->instance(PushSender::class, new class implements PushSender {
            public function send(array $deliveries): array
            {
                throw new \RuntimeException('network down');
            }
        });
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);
        $this->subscribe($ben, $this->device('ben'));

        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages", ['body' => 'hi'])->assertCreated();
        $this->assertSame(1, \App\Models\ChatMessage::count());
    }
}
