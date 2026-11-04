<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Ang padala ng sequence (chatbot:sequence-pool) sa gateway ay nagtatala ng address na dinaanan (chatbot_replies.route). */
class ShowRealRouteSequenceTest extends SequenceHandoffTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php']]);
        config(['services.gateway.url' => 'https://gw.test/api', 'services.gateway.key' => 'gk-test-not-real', 'services.gateway.urls' => 'incepxion=https://incepxion.test/api']);
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'provider', 'gateway');
    }

    private function sendOne(string $psid): void
    {
        $seq = $this->sequence('Route ' . $psid, [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, $psid, '1002');
        $this->steps();
        $this->round();
    }

    public function test_S_224_2_4_a_sequence_send_records_the_address_it_went_through(): void
    {
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'gateway_app', 'incepxion', false, null, true);
        $this->sendOne('8001');
        $this->assertCount(1, $this->calls);
        $this->answer($this->calls[0], 202, ['status' => 'queued']);
        $this->pool->tick(false);

        $this->assertSame([['forbeast', 'incepxion']], ChatbotReply::where('psid', 8001)->get()->map(fn ($r) => [$r->via, $r->route])->all());
    }

    public function test_S_224_2_5_a_sequence_send_finished_after_a_crash_keeps_the_address_from_the_call(): void
    {
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'gateway_app', 'incepxion', false, null, true);
        $this->sendOne('8002');
        $this->assertCount(1, $this->calls);
        $t = DB::table(BotcakeSendQueue::TABLE)->where('psid', '8002')->first();
        $this->assertSame('incepxion', json_decode((string) $t->result, true)['route'] ?? null, 'stored before the call');

        // Ang sweep pagkatapos mamatay ang pool: walang via at ref sa resulta, kinukuha sa naitala bago ang tawag.
        BotcakeSendQueue::finishSequence($t, $t->claimed_by, ['status' => 'failed', 'error' => BotcakeSendQueue::noAnswer('forbeast'), 'http' => null]);
        $this->assertSame([['forbeast', 'incepxion']], ChatbotReply::where('psid', 8002)->get()->map(fn ($r) => [$r->via, $r->route])->all());
    }

    public function test_S_224_2_6_a_sequence_send_on_the_default_address_records_forbeast_and_a_botcake_one_records_nothing(): void
    {
        $this->sendOne('8003');
        $this->answer($this->calls[0], 202, ['status' => 'queued']);
        $this->pool->tick(false);
        $this->assertSame([['forbeast', 'forbeast']], ChatbotReply::where('psid', 8003)->get()->map(fn ($r) => [$r->via, $r->route])->all());

        $calls = count($this->calls);
        $seq = $this->sequence('Botcake', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '8004', '1001');
        $this->steps();
        $this->round();
        $this->answer($this->calls[$calls]);
        $this->pool->tick(false);
        $this->assertSame([['botcake', null]], ChatbotReply::where('psid', 8004)->get()->map(fn ($r) => [$r->via, $r->route])->all());
        $this->assertArrayNotHasKey('route', (array) json_decode((string) DB::table(BotcakeSendQueue::TABLE)->where('psid', '8004')->value('result'), true), 'a Botcake ticket keeps today\'s result');
    }
}
