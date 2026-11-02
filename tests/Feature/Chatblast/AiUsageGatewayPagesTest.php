<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatbotAiUsage;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 216, S-216.2: ang usage at ang tanong ng AI load ay para lang sa page na dumadaan sa gateway (chatbot.provider =
 * gateway, ang parehong tingin ng padala). Ang Botcake page ay may gastos pa rin pero no_gateway, walang padala sa Forbeast.
 */
class AiUsageGatewayPagesTest extends AiKeyPoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->live('Page Uno');
    }

    /** @return array<int, HttpRequest> */
    private function forbeastCalls(string $path): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $path))->map(fn ($p) => $p[0])->values()->all();
    }

    private function botcake(string $page): void
    {
        app(PageSettings::class)->set($page, 'chatbot', 'provider', 'botcake', false, null, true);
    }

    public function test_S_216_2_1_a_gateway_page_gets_pending_usage_and_it_is_sent_as_today(): void
    {
        $this->onGateway('Page Uno');
        $this->says('hello', 'Page Uno');
        $row = $this->lastRow();
        $this->assertSame([27, 'pending', 'forbeast'], [(int) $row->cost_micros, $row->usage_status, $row->usage_gateway]);
        $this->assertCount(1, $this->forbeastCalls('/v1/site/ai-load'));

        (new SendChatbotAiUsage())->handle();
        $calls = $this->forbeastCalls('/v1/site/ai-usage');
        $this->assertCount(1, $calls);
        $this->assertSame([$row->request_id], array_column($calls[0]->data()['records'], 'client_ref'));
        $this->assertSame('sent', DB::table(ChatbotAiQueue::TABLE)->value('usage_status'));
    }

    public function test_S_216_2_2_a_botcake_page_keeps_its_cost_but_gets_no_gateway_and_nothing_is_sent(): void
    {
        $this->says('hello', 'Page Uno');
        $row = $this->lastRow();
        $this->assertSame([27, 'no_gateway', null], [(int) $row->cost_micros, $row->usage_status, $row->usage_gateway]);
        $this->assertSame(['Reply: answer by 1111'], $this->sent());

        (new SendChatbotAiUsage())->handle();
        $this->assertSame([], $this->forbeastCalls('/v1/site/ai-usage'));
        $this->assertSame('no_gateway', DB::table(ChatbotAiQueue::TABLE)->value('usage_status'));
    }

    public function test_S_216_2_3_a_botcake_page_is_never_load_checked_and_its_ai_never_stops_for_load(): void
    {
        // Kung tatanungin, "stopped" ang isasagot ng Forbeast: hindi dapat umabot doon.
        $this->forbeast = [fn (HttpRequest $r) => Http::response(['pages' => [['page_id' => '1001', 'state' => 'stopped', 'balance_php' => '-0.01', 'note' => 'AI stopped: no load.']]])];
        $this->says('hello', 'Page Uno');
        $this->assertSame([], $this->forbeastCalls('/v1/site/ai-load'));
        $this->assertSame(['1111'], $this->calledKeys());
        $this->assertSame(['Reply: answer by 1111'], $this->sent());

        // Ang refresh kada minuto at ang note sa /chatbot/pages: wala rin ang Botcake page.
        $this->assertSame([], app(\App\Services\Chatbot\ChatbotAiLoad::class)->known());
        $this->assertSame(['Page Uno' => null], app(\App\Services\Chatbot\ChatbotAiLoad::class)->shown(['Page Uno' => '1001']));
    }

    public function test_S_216_2_4_a_page_moved_between_botcake_and_the_gateway_follows_the_new_path_and_older_rows_stay(): void
    {
        $this->says('one', 'Page Uno');
        $this->onGateway('Page Uno');
        $this->says('two', 'Page Uno');
        $this->botcake('Page Uno');
        $this->says('three', 'Page Uno');

        $rows = DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->get(['usage_status', 'usage_gateway'])->map(fn ($r) => [$r->usage_status, $r->usage_gateway])->all();
        $this->assertSame([['no_gateway', null], ['pending', 'forbeast'], ['no_gateway', null]], $rows);
    }
}
