<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Ang filter builder ng /chatbot/inbox: ang mga kondisyon (AND) sa customer field, Ordered, huling mensahe, unang chat,
 * huling sagot ng bot at Human/Bot. Fixture: page 1001, window 7d (ngayon ay 2026-10-19 12:00).
 */
class ChatbotInboxFilterBuilderTest extends ChatblastTestCase
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
        ]]);
        $this->page('Page Uno', '1001');
        $this->travelTo('2026-10-19 12:00:00');

        // 9001..9006: huling mensahe 10-19, 10-18, 10-17, 10-16, 10-15, 10-14 (10:00).
        foreach (range(1, 6) as $i) $this->in('900' . $i, 'hi', '2026-10-' . (20 - $i) . ' 10:00:00');
        $this->in('9001', 'first', '2026-10-01 08:00:00');                                  // unang chat ng 9001 ay labas ng window
        $this->field('9001', 'gpt phone number', '0917');
        $this->field('9001', 'cxd', 'Hot Lead');
        $this->field('9002', 'gpt phone number', '   ');
        $this->field('9004', 'gpt phone number', '0918');
        $this->field('9004', 'cxd', 'cold');
        $this->actingAs($this->user());
    }

    private function in(string $psid, string $message, string $at): void
    {
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'C' . $psid, 'message' => $message, 'payload' => [], 'received_at' => $at]);
    }

    private function out(string $psid, string $at, string $status): void
    {
        ChatbotReply::create(['page_id' => 1001, 'psid' => (int) $psid, 'chat_id' => null, 'flow_id' => null, 'node_id' => 'n2', 'kind' => 'send',
            'content' => [['type' => 'text', 'text' => 'x']], 'status' => $status, 'error' => $status === 'failed' ? 'no' : null, 'sent_at' => $at]);
    }

    private function ev(string $psid, string $at): void
    {
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => (int) $psid, 'type' => 'order', 'detail' => '{}', 'created_at' => $at]);
    }

    private function field(string $psid, string $key, string $value): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => 1001, 'psid' => (int) $psid, 'key' => $key, 'value' => $value, 'updated_at' => now()]);
    }

    /** @return array{rows: array, counts: array, total: int} */
    private function fetch(array $conditions, string $extra = ''): array
    {
        return $this->getJson('/chatbot/inbox/conversations?page_id=1001&' . ($extra ? $extra . '&' : '') . 'conditions=' . urlencode(json_encode($conditions)))->assertOk()->json();
    }

    private function psids(array $conditions, string $extra = ''): array
    {
        return collect($this->fetch($conditions, $extra)['rows'])->pluck('psid')->sort()->values()->all();
    }

    private function field_c(string $key, string $op, ?string $value = null): array
    {
        return ['type' => 'field', 'key' => $key, 'op' => $op, 'value' => $value];
    }

    public function test_S_129_1_each_operator_on_a_customer_field(): void
    {
        $all = ['9001', '9002', '9003', '9004', '9005', '9006'];
        $this->assertSame($all, $this->psids([]));
        $this->assertSame(['9001', '9004'], $this->psids([$this->field_c('gpt phone number', 'has_value')]));
        $this->assertSame(['9002', '9003', '9005', '9006'], $this->psids([$this->field_c('GPT PHONE NUMBER', 'empty')]));   // blangko at walang row
        $this->assertSame(['9001'], $this->psids([$this->field_c('cxd', 'is', '  hot LEAD ')]));
        $this->assertSame(['9001'], $this->psids([$this->field_c('cxd', 'contains', 'LEAD')]));
        $this->assertSame(['9002', '9003', '9004', '9005', '9006'], $this->psids([$this->field_c('cxd', 'not_contains', 'lead')]));
        $this->assertSame(['9001', '9004'], $this->psids([$this->field_c('cxd', 'not_contains', 'zzz'), $this->field_c('gpt phone number', 'has_value')]));
        $this->assertSame([], $this->psids([$this->field_c('cxd', 'is', 'hot')]));                                           // buo ang tugma, hindi bahagi
        $this->assertSame([], $this->psids([$this->field_c('cxd', 'contains', '%')]));                                      // ang % ay hindi wildcard
    }

    public function test_S_129_2_ordered_yes_no_with_a_date_range(): void
    {
        $this->ev('9001', '2026-10-19 09:00:00');
        $this->ev('9002', '2026-10-15 09:00:00');
        $this->ev('9003', '2026-10-01 09:00:00');                                                                          // labas ng window
        $this->assertSame(['9001', '9002'], $this->psids([['type' => 'ordered', 'value' => 'yes']]));
        $this->assertSame(['9003', '9004', '9005', '9006'], $this->psids([['type' => 'ordered', 'value' => 'no']]));
        $this->assertSame(['9001'], $this->psids([['type' => 'ordered', 'value' => 'yes', 'from' => '2026-10-19']]));
        $this->assertSame(['9002'], $this->psids([['type' => 'ordered', 'value' => 'yes', 'from' => '2026-10-14', 'to' => '2026-10-16']]));
        $this->assertSame(['9001', '9003', '9004', '9005', '9006'], $this->psids([['type' => 'ordered', 'value' => 'no', 'from' => '2026-10-14', 'to' => '2026-10-16']]));
    }

    public function test_S_129_3_last_message_and_first_chat_date_ranges(): void
    {
        $this->assertSame(['9002', '9003'], $this->psids([['type' => 'last_message', 'from' => '2026-10-17', 'to' => '2026-10-18']]));
        $this->assertSame(['9001', '9002'], $this->psids([['type' => 'last_message', 'from' => '2026-10-18']]));
        $this->assertSame(['9005', '9006'], $this->psids([['type' => 'last_message', 'to' => '2026-10-15']]));
        $this->assertSame(['9001'], $this->psids([['type' => 'first_chat', 'from' => '2026-10-01', 'to' => '2026-10-02']]));
        $this->assertSame(['9002', '9003', '9004', '9005', '9006'], $this->psids([['type' => 'first_chat', 'from' => '2026-10-10']]));
    }

    public function test_S_129_4_last_bot_reply_failed_vs_sent_and_human_bot(): void
    {
        $this->out('9001', '2026-10-19 10:30:00', 'failed');
        $this->out('9001', '2026-10-19 10:40:00', 'sent');       // huli ay sent
        $this->out('9002', '2026-10-19 10:30:00', 'sent');
        $this->out('9002', '2026-10-19 10:40:00', 'failed');     // huli ay failed
        $this->assertSame(['9002'], $this->psids([['type' => 'bot_reply', 'value' => 'failed']]));
        $this->assertSame(['9001'], $this->psids([['type' => 'bot_reply', 'value' => 'sent']]));
        $this->field('9003', '__bot_paused', '1');
        $this->assertSame(['9003'], $this->psids([['type' => 'handler', 'value' => 'human']]));
        $this->assertSame(['9001', '9002', '9004', '9005', '9006'], $this->psids([['type' => 'handler', 'value' => 'bot']]));
    }

    public function test_S_129_5_conditions_are_anded_and_combine_with_the_tab_search_status_and_counts(): void
    {
        $this->ev('9001', '2026-10-19 09:00:00');
        $this->ev('9004', '2026-10-19 09:00:00');
        $this->field('9001', 'chat status', 'LOOP 1');
        $this->field('9004', 'chat status', 'LOOP 2');
        $this->field('9004', '__bot_paused', '1');
        $c = [['type' => 'ordered', 'value' => 'yes'], $this->field_c('gpt phone number', 'has_value'), ['type' => 'last_message', 'from' => '2026-10-17']];
        $this->assertSame(['9001'], $this->psids($c));                                                                      // 9004 ay labas ng petsa
        $this->assertSame(['9001', '9004'], $this->psids([['type' => 'ordered', 'value' => 'yes'], $this->field_c('gpt phone number', 'has_value')]));
        $this->assertSame(['9004'], $this->psids([['type' => 'ordered', 'value' => 'yes']], 'tab=human'));
        $this->assertSame(['9001'], $this->psids([['type' => 'ordered', 'value' => 'yes']], 'status=loop1'));
        $this->assertSame(['9001'], $this->psids([['type' => 'ordered', 'value' => 'yes']], 'q=9001'));

        $r = $this->fetch([['type' => 'ordered', 'value' => 'yes']]);
        $this->assertSame([2, 2], [$r['total'], count($r['rows'])]);
        $this->assertSame(['ordered' => 2, 'loop1' => 1, 'loop2' => 1, 'loop3' => 0, 'failed' => 0, 'phone' => 0], $r['counts']);
        $this->assertSame(6, $this->fetch([])['total']);
    }

    public function test_S_129_6_invalid_conditions_are_rejected_and_the_field_keys_are_listed(): void
    {
        $bad = [
            [['type' => 'field', 'key' => 'cxd', 'op' => 'drop table']],
            [['type' => 'field', 'key' => '__bot_paused', 'op' => 'has_value']],
            [['type' => 'field', 'key' => '', 'op' => 'has_value']],
            [['type' => 'field', 'key' => 'cxd', 'op' => 'is']],
            [['type' => 'nope']],
            [['type' => 'ordered', 'value' => 'maybe']],
            [['type' => 'last_message']],
            [['type' => 'last_message', 'from' => '10/19/2026']],
            [['type' => 'last_message', 'from' => '2026-10-19', 'to' => '2026-10-01']],
            [['type' => 'bot_reply', 'value' => 'skipped']],
            [['type' => 'handler', 'value' => 'cat']],
            array_fill(0, 11, ['type' => 'handler', 'value' => 'bot']),
            ['not an object'],
        ];
        foreach ($bad as $conditions) {
            $this->getJson('/chatbot/inbox/conversations?page_id=1001&conditions=' . urlencode(json_encode($conditions)))->assertStatus(422);
        }
        $this->getJson('/chatbot/inbox/conversations?page_id=1001&conditions=' . urlencode('{broken'))->assertStatus(422);
        $this->assertCount(6, $this->fetch(array_fill(0, 10, ['type' => 'handler', 'value' => 'bot']))['rows']);              // 10 ang pinakamarami

        $this->field('9002', '__wait', 'x');
        $this->assertSame([['key' => 'cxd', 'label' => 'CXD'], ['key' => 'gpt phone number', 'label' => 'GPT PHONE NUMBER']],
            $this->getJson('/chatbot/inbox/field-keys?page_id=1001')->assertOk()->json('keys'));
        $this->getJson('/chatbot/inbox/field-keys?page_id=555')->assertStatus(422);
    }
}
