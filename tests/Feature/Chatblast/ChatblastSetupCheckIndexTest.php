<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Services\ChatblastSetupCheck;
use Illuminate\Support\Facades\DB;

/**
 * Spec 255: ang delivered check (sentVia) ay bumibilang ng parehong mga row gaya ng dati (LOWER(TRIM(page_name)) = key),
 * pero ang query nito sa botcake_messages ay "page_name IN (...)" na walang function sa column, para magamit ang
 * (page_name, status, sent_at) index.
 */
class ChatblastSetupCheckIndexTest extends ChatblastTestCase
{
    private function send(string $page, int $minutesAgo, string $flow = '9100', bool $test = false, string $status = 'sent'): void
    {
        BotcakeMessage::create(['page_name' => $page, 'waybill' => 'JT' . random_int(100000, 999999), 'kind' => 'in_transit', 'psid' => '7001',
            'status' => $status, 'is_test' => $test,
            'steps' => [['step' => 'write'], ['step' => 'flow', 'request' => ['psid' => '7001', 'flow_id' => (int) $flow]]],
            'queued_at' => now()->subMinutes($minutesAgo + 1), 'sent_at' => now()->subMinutes($minutesAgo)]);
    }

    private function sentVia(string $page, string $since = ''): int
    {
        return app(ChatblastSetupCheck::class)->sentVia($page, '9100', $since);
    }

    public static function names(): array
    {
        return [
            'exact'          => ['Page Uno', 'Page Uno'],
            'different case' => ['PAGE uno', 'Page Uno'],
            'padded'         => ['  Page Uno ', 'Page Uno'],
            'key asked padded and in other case' => ['Page Uno', ' page UNO  '],
        ];
    }

    /** @dataProvider names */
    public function test_S_255_1_the_same_rows_count_for_exact_other_case_and_padded_names(string $stored, string $asked): void
    {
        $this->send($stored, 90);
        $this->send($stored, 120);
        $this->send($stored, 30);                  // too early: does not count
        $this->send($stored, 90, '5501');          // another flow: does not count
        $this->send($stored, 90, '9100', true);    // a test send: does not count
        $this->send($stored, 90, '9100', false, 'failed');
        $this->send('Page Dos', 90);               // another page
        $this->send('Page  Uno', 90);              // inner spaces differ: not the same key, as before
        $this->send('Page Uno 2', 90);

        $this->assertSame(2, $this->sentVia($asked));
        $this->assertSame(1, $this->sentVia($asked, now()->subMinutes(100)->format('Y-m-d H:i:s')), 'since still applies');
    }

    public function test_S_255_1_every_stored_variant_of_the_page_counts_together(): void
    {
        foreach (['Page Uno', 'PAGE UNO', 'page uno', ' Page Uno '] as $name) $this->send($name, 90);
        $this->send('Page Dos', 90);

        $this->assertSame(4, $this->sentVia('Page Uno'));
        $this->assertSame(0, $this->sentVia('Page Tres'), 'a page with no rows reads nothing');
    }

    public function test_S_255_1_the_query_does_not_wrap_page_name_in_a_function(): void
    {
        $this->send('PAGE UNO', 90);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(1, $this->sentVia('Page Uno'));

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $mine = array_values(array_filter($queries, fn ($q) => str_contains($q, 'botcake_messages') && !str_contains(strtolower($q), 'sqlite_master')));
        $this->assertNotEmpty($mine);
        foreach ($mine as $q) {
            $this->assertDoesNotMatchRegularExpression('/\b(lower|trim|btrim|upper)\s*\(\s*[`"]?page_name/i', $q, 'page_name is not wrapped in a function');
        }
        $read = array_values(array_filter($mine, fn ($q) => str_contains($q, 'sent_at')));
        $this->assertCount(1, $read);
        $this->assertMatchesRegularExpression('/[`"]page_name[`"] in \(/i', $read[0], 'the read filters page_name with IN, so the (page_name, status, sent_at) index serves it');
    }
}
