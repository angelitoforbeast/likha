<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Services\BotcakeChatGuard;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 227: ang send_status na dumating bago naisulat ang row ng padala (ang sagot na 202 ng gateway ay hindi pa
 * nababasa ng Likha) ay sinusubukan ulit pagkalipas ng ilang segundo, hanggang mga 2 minuto, saka itinatala kapag nandiyan
 * na ang row. Pagkatapos ng huling subok: unknown, gaya ng dati.
 */
class EarlySendStatusTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-11-03 20:00:00');
        Queue::fake();
    }

    private function reply(string $ref, ?string $confirmed = null): int
    {
        return DB::table('chatbot_replies')->insertGetId(['page_id' => 1001, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'http' => 202,
            'sent_at' => now()->toDateTimeString(), 'via' => 'forbeast', 'client_ref' => $ref]
            + ($confirmed !== null ? ['confirmed_status' => 'sent', 'confirmed_at' => $confirmed] : []));
    }

    /** Ang send_status gaya ng galing sa controller (HandleGatewayEvent::fields). */
    private static function event(?string $ref): array
    {
        return HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => 9, 'type' => 'send_status', 'page_id' => '1001', 'psid' => '7001',
            'send' => ['public_id' => 'snd_1', 'client_ref' => $ref, 'status' => 'sent', 'accepted_at' => '2026-11-03T11:59:58.000Z',
                'sent_at' => '2026-11-03T12:00:03.000Z', 'message_count' => 1, 'sent_count' => 1, 'error_code' => null, 'error_subcode' => null, 'error' => null]]);
    }

    private function handle(HandleGatewayEvent $job): void
    {
        $job->handle(app(PageSettings::class), app(BotcakeChatGuard::class));
    }

    private function counted(string $what): int
    {
        return (int) Cache::get('gateway-events:' . now('Asia/Manila')->toDateString() . ':' . $what, 0);
    }

    /** Ang mga job na naipasa ulit, luma muna. @return array<int, HandleGatewayEvent> */
    private function retries(): array
    {
        return Queue::pushed(HandleGatewayEvent::class)->values()->all();
    }

    private function confirmed(int $id): ?string
    {
        return DB::table('chatbot_replies')->where('id', $id)->value('confirmed_at');
    }

    public function test_S_227_2_1_a_send_status_before_its_reply_row_is_applied_when_the_row_appears(): void
    {
        $this->handle(new HandleGatewayEvent(self::event('likha-early')));
        $this->assertSame(0, $this->counted('send_status_unknown'), 'not counted unknown on the first miss');
        $this->assertCount(1, $this->retries(), 'kept: sent again with a delay');
        $this->assertSame(5, $this->retries()[0]->delay, 'seconds');

        $id = $this->reply('likha-early'); // the sending path writes the row a moment later
        $this->handle($this->retries()[0]);

        $this->assertSame('2026-11-03 20:00:03', $this->confirmed($id), 'the gateway sent_at (UTC 12:00:03) in app time');
        $this->assertSame(1, $this->counted('send_status'));
        $this->assertSame(1, $this->counted('send_status_retry'));
        $this->assertCount(1, $this->retries(), 'applied: no further retry');
    }

    public function test_S_227_2_2_after_the_last_try_it_is_counted_unknown_and_not_retried_again(): void
    {
        $this->handle(new HandleGatewayEvent(self::event('likha-never')));
        for ($i = 0; $i < 10 && count($this->retries()) > $i; $i++) $this->handle($this->retries()[$i]);

        $tries = $this->retries();
        $this->assertSame([5, 15, 40, 60], array_map(fn ($j) => $j->delay, $tries), 'about two minutes in all');
        $this->assertSame([1, 2, 3, 4], array_map(fn ($j) => $j->event['send_try'], $tries));
        $this->assertSame(1, $this->counted('send_status_unknown'), 'unknown once, after the last try');
        $this->assertSame(4, $this->counted('send_status_retry'));
    }

    public function test_S_227_2_3_a_repeat_changes_nothing_and_is_not_retried(): void
    {
        $id = $this->reply('likha-done', '2026-11-03 19:59:00');
        $this->handle(new HandleGatewayEvent(self::event('likha-done')));

        $this->assertSame('2026-11-03 19:59:00', $this->confirmed($id));
        $this->assertSame(1, $this->counted('send_status_repeat'));
        $this->assertSame([], $this->retries());
    }

    public function test_S_227_2_4_an_event_without_a_client_ref_and_no_match_is_unknown_at_once(): void
    {
        $this->handle(new HandleGatewayEvent(self::event(null)));

        $this->assertSame(1, $this->counted('send_status_unknown'));
        $this->assertSame([], $this->retries());
    }

    public function test_S_227_2_5_the_retry_carries_no_queue_time_and_an_event_from_the_live_release_is_try_zero(): void
    {
        $live = self::event('likha-live');
        $this->assertArrayHasKey('queued_at', $live);
        $this->assertArrayNotHasKey('send_try', $live, 'the shape queued by the live release');

        $this->handle(new HandleGatewayEvent($live));
        $retry = $this->retries()[0]->event;

        $this->assertArrayNotHasKey('queued_at', $retry, 'the delay is not read as queue lag');
        $this->assertSame(1, $retry['send_try']);
        $this->assertSame(array_diff_key($live, ['queued_at' => 1]), array_diff_key($retry, ['send_try' => 1]), 'the same event otherwise');
    }
}
