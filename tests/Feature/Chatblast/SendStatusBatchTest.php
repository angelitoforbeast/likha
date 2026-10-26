<?php

namespace Tests\Feature\Chatblast;

use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Spec 163: ang send_status ng Forbeast na sabay-sabay (isang POST, maraming event ng iisang page). Bawat event ay gaya ng
 * iisang send_status: sariling sent_at, isang beses lang. Page Uno (1001) at Page Dos (1002) ay may sariling webhook secret.
 */
class SendStatusBatchTest extends ChatblastTestCase
{
    private const SECRET_UNO = 'whsec-uno-163-aaaaaaaaaaaaaaaa';
    private const SECRET_DOS = 'whsec-dos-163-bbbbbbbbbbbbbbbb';

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
        config(['queue.chatbot_connection' => 'sync']);
        $this->travelTo('2026-10-26 20:00:00');
        $s = app(PageSettings::class);
        foreach (['Page Uno' => ['1001', self::SECRET_UNO], 'Page Dos' => ['1002', self::SECRET_DOS]] as $name => [$id, $secret]) {
            $this->page($name, $id);
            $s->set($name, 'chatbot', 'provider', 'gateway');
            $s->set($name, 'forbeast', 'webhook_secret', $secret, true, null, true);
        }
    }

    private function reply(string $ref, string $page = '1001'): int
    {
        return DB::table('chatbot_replies')->insertGetId(['page_id' => (int) $page, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send',
            'status' => 'sent', 'http' => 202, 'sent_at' => '2026-10-26 19:00:00', 'via' => 'forbeast', 'client_ref' => $ref]);
    }

    /** Ang send_status gaya ng ipinapadala ng Forbeast ngayon (isang event). $sentAt: oras sa Manila. */
    private static function event(string $ref, string $sentAt, string $page = '1001', int $id = 1): array
    {
        $iso = fn (string $local) => \Carbon\Carbon::parse($local, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s.v\Z');

        return ['kind' => 'event', 'event_id' => $id, 'type' => 'send_status', 'page_id' => $page, 'psid' => '7001',
            'send' => ['public_id' => 'snd_' . $ref, 'client_ref' => $ref, 'status' => 'sent', 'accepted_at' => $iso('2026-10-26 19:00:00'),
                'sent_at' => $iso($sentAt), 'message_count' => 1, 'sent_count' => 1, 'message_ids' => ['m_' . $id], 'error_code' => null, 'error_subcode' => null, 'error' => null]];
    }

    private function send(array $body, string $secret = self::SECRET_UNO, bool $batch = true)
    {
        $raw = json_encode($body);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $raw, $secret)]
            + ($batch ? ['HTTP_X_GATEWAY_BATCH' => '1'] : []);

        return $this->call('POST', '/api/gateway/events', [], [], [], $headers, $raw);
    }

    private function confirmed(int $id): ?string
    {
        return DB::table('chatbot_replies')->where('id', $id)->value('confirmed_at');
    }

    public function test_S_163_1_a_batch_confirms_each_reply_with_its_own_sent_at_and_a_repeat_changes_nothing(): void
    {
        $ids = [$this->reply('ref-a'), $this->reply('ref-b'), $this->reply('ref-c')];
        $events = [self::event('ref-a', '2026-10-26 19:00:04', id: 11), self::event('ref-b', '2026-10-26 19:00:09', id: 12), self::event('ref-c', '2026-10-26 19:01:30', id: 13)];

        $this->send(['kind' => 'batch', 'events' => $events])->assertOk()->assertExactJson(['ok' => true, 'accepted' => 3]);
        $this->assertSame(['2026-10-26 19:00:04', '2026-10-26 19:00:09', '2026-10-26 19:01:30'], array_map(fn ($id) => $this->confirmed($id), $ids));

        // Ang ulit ng buong batch (retry ng Forbeast) ay walang binabago, kahit iba ang sent_at.
        $events[0] = self::event('ref-a', '2026-10-26 19:30:00', id: 11);
        $this->send(['kind' => 'batch', 'events' => $events])->assertOk()->assertExactJson(['ok' => true, 'accepted' => 3]);
        $this->assertSame('2026-10-26 19:00:04', $this->confirmed($ids[0]));

        // Ang iisang event (gaya ngayon) ay gumagana pa rin.
        $single = $this->reply('ref-d');
        $this->send(self::event('ref-d', '2026-10-26 19:02:00', id: 14), batch: false)->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('2026-10-26 19:02:00', $this->confirmed($single));
    }

    /** [ang body, ang secret na pumirma, ang status na inaasahan] */
    public static function refused(): array
    {
        $uno = self::event('ref-a', '2026-10-26 19:00:04', id: 21);

        return [
            'bad signature' => [['kind' => 'batch', 'events' => [$uno]], 'not-the-secret', 401],
            'signed with the other page secret' => [['kind' => 'batch', 'events' => [$uno]], self::SECRET_DOS, 401],
            'mixed pages' => [['kind' => 'batch', 'events' => [$uno, self::event('ref-x', '2026-10-26 19:00:05', '1002', 22)]], self::SECRET_UNO, 422],
            'not a send_status' => [['kind' => 'batch', 'events' => [$uno, ['kind' => 'event', 'type' => 'message', 'page_id' => '1001', 'psid' => '7001', 'mid' => 'm_x', 'text' => 'hi']]], self::SECRET_UNO, 422],
            'no events' => [['kind' => 'batch', 'events' => []], self::SECRET_UNO, 422],
        ];
    }

    /** @dataProvider refused */
    public function test_S_163_1b_a_refused_batch_confirms_nothing(array $body, string $secret, int $status): void
    {
        $id = $this->reply('ref-a');

        $this->send($body, $secret)->assertStatus($status);
        $this->assertNull($this->confirmed($id));
    }
}
