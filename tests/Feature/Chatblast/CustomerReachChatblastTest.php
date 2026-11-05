<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\Chatbot\CustomerReach;
use App\Services\ChatblastSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang Chatblast at ang customer na hindi maabot: ang send_flow na sinagot ng #551 ay nagmamarka, at ang markadong customer
 * ay nilalaktawan (sa job at sa simula ng pool), itinatala sa botcake_messages. Fake HTTP lang.
 */
class CustomerReachChatblastTest extends ChatblastTestCase
{
    private const BASE = 'botcake.io/api/public_api/v1/pages/1001';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        $this->travelTo('2026-11-05 14:03:00');
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', '7001');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', '7002');
        app(Chatblast::class)->build('delivering');
    }

    private function row(string $waybill): BotcakeMessage
    {
        return BotcakeMessage::where('waybill', $waybill)->where('is_test', false)->sole();
    }

    /** Botcake na sumasagot ng tama; $flow: ang sagot ng send_flow. */
    private function botcake(?array $flow = null): void
    {
        $written = [];
        Http::fake([self::BASE . '/*' => function (Request $req) use (&$written, $flow) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            preg_match('#/customer/([^/]+)/#', $path, $m);
            if (str_ends_with($path, '/flows/send_flow')) return Http::response($flow ?? ['success' => true]);
            if ($req->method() === 'POST') {
                $written[$m[1]] = $req->data()['data'][0]['value'];
                return Http::response(['success' => true]);
            }
            return Http::response(['success' => true, 'data' => [['custom_field_id' => 7701, 'value' => $written[$m[1]] ?? null]]]);
        }]);
    }

    private function mark(string $psid, string $at = '2026-11-05 13:00:00'): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => 1001, 'psid' => (int) $psid, 'key' => CustomerReach::KEY,
            'value' => json_encode(['code' => 551, 'at' => $at]), 'updated_at' => now()]);
    }

    public function test_S_234_1_1_a_chatblast_send_flow_refused_with_551_marks_the_customer(): void
    {
        $this->botcake(['success' => false, 'message' => "(#551) This person isn't available right now."]);

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);

        $this->assertSame('failed', $this->row('WB-001')->status);
        $v = DB::table('chatbot_customer_values')->where('page_id', 1001)->where('psid', 7001)->where('key', CustomerReach::KEY)->value('value');
        $this->assertSame(['code' => 551, 'at' => '2026-11-05 14:03:00'], json_decode((string) $v, true));
    }

    public function test_S_234_2_2_a_chatblast_to_a_marked_customer_is_skipped_with_the_reason_and_no_botcake_call(): void
    {
        $this->botcake();
        $this->mark('7001');

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id, $this->row('WB-002')->id], 1);

        $skipped = $this->row('WB-001');
        $this->assertSame(['skipped', "Customer can't be reached (#551) since 2026-11-05 13:00"], [$skipped->status, $skipped->reason]);
        $this->assertNotNull($skipped->dedupe_key, 'walang bagong row sa bawat kuha');
        $this->assertSame('sent', $this->row('WB-002')->status);
        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), '/customer/7001/'))->all());
    }

    public function test_S_234_2_2_the_pool_skips_a_chatblast_whose_customer_was_marked_while_it_waited(): void
    {
        $m = $this->row('WB-001');
        $this->mark('7001');

        $this->assertSame('', app(ChatblastSender::class)->poolStart($m, ChatblastSender::customerLockKey('Page Uno', '7001')));

        $this->assertSame('skipped', $m->fresh()->status);
    }

    public function test_S_234_2_2_a_page_name_from_the_order_that_differs_only_in_case_still_marks_and_skips(): void
    {
        BotcakeMessage::query()->update(['page_name' => 'PAGE UNO']);
        $this->botcake(['success' => false, 'message' => "(#551) This person isn't available right now."]);
        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);
        $this->assertNotNull(CustomerReach::skipReason('1001', '7001'), 'may marka kahit iba ang laki ng titik');

        BotcakeMessage::whereKey($this->row('WB-001')->id)->update(['status' => 'ready']);
        $this->travel(10)->minutes(); // lampas sa gap ng customer pagkatapos ng unang padala
        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);

        $this->assertSame('skipped', $this->row('WB-001')->status);
    }

    public function test_a_mark_older_than_30_days_no_longer_skips(): void
    {
        $this->botcake();
        $this->mark('7001', '2026-10-05 14:00:00');

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);

        $this->assertSame('sent', $this->row('WB-001')->status);
    }
}
