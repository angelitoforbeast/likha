<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotContentCheck;
use App\Models\ChatbotFlow;
use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ImageLinkCheck;
use App\Services\Chatbot\PageContentCheck;
use App\Services\Chatbot\PageExpectedItem;
use App\Services\PageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang content check ng /chatbot/pages: ang item at presyo ng Main Flow, ang larawan at ang ad video, laban sa item na
 * talagang ino-order. Fake ang OpenAI (bawat sagot ay ayon sa text o link na nasa tanong): walang tawag na lumalabas.
 */
class ChatbotContentCheckTest extends ChatblastTestCase
{
    private const IMG = 'https://img.example.test/';

    /** @var array<string, mixed> needle sa tanong => sagot (array = JSON, string = hilaw na content, int = HTTP status) */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.openai.key' => 'test-key']);
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_11_02_106000_create_chatbot_content_answers_table.php',
        ]]);
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_name')->nullable();
            $t->string('item_name')->nullable();
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->integer('mode_cod_int')->nullable();
            $t->date('effective_date');
        });
        Schema::create('gpt_video_analyses', function (Blueprint $t) {
            $t->id();
            $t->string('file_name');
            $t->string('file_sha256', 64);
            $t->text('summary')->nullable();
            $t->string('item_name', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('gpt_ad_generations', function (Blueprint $t) {
            $t->id();
            $t->string('page_filter')->nullable();
            $t->unsignedBigInteger('video_analysis_id')->nullable();
            $t->timestamps();
        });

        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $this->app->instance(ImageLinkCheck::class, new ImageLinkCheck(fn (string $host) => ['93.184.216.34']));
        Http::fake([
            self::IMG . '*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
            'api.openai.com/*' => function (HttpRequest $request) {
                $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($this->answers as $needle => $answer) {
                    if (!str_contains($body, (string) $needle)) continue;
                    if (is_int($answer)) return Http::response(['error' => ['message' => 'down']], $answer);

                    return Http::response(['choices' => [['message' => ['content' => is_array($answer) ? json_encode($answer) : $answer]]],
                        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]]);
                }

                return Http::response(['error' => ['message' => 'no fake answer']], 500);
            },
        ]);

        $flow = $this->flow('{{Main Flow}} {{Main Flow 2}} {{Fill-Up Form}}');
        app(ChatbotPage::class)->link('Page Uno', $flow);
        app(ChatbotPage::class)->link('Page Dos', $flow);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n0', 'type' => 'start'], ['id' => 'n1', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => []])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function orders(string $page, string $item, string $cod, int $count, int $daysAgo = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            MacroOutput::create(['PAGE' => $page, 'ITEM_NAME' => $item, 'COD' => $cod, 'ts_date' => now('Asia/Manila')->subDays($daysAgo)->toDateString()]);
        }
    }

    private function aiCalls(): int
    {
        return count(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com')));
    }

    private function answer(string $item, bool $same, int $confidence, array $prices = []): array
    {
        return ['found_item' => $item, 'same_product' => $same, 'confidence' => $confidence, 'prices' => $prices];
    }

    private function content(string $page = 'Page Uno', bool $ask = true): array
    {
        return app(PageContentCheck::class)->run($page, $ask);
    }

    private function check(string $page = 'Page Uno'): array
    {
        if (!auth()->check()) $this->actingAs($this->user());

        return $this->getJson('/chatbot/check?page_name=' . urlencode($page))->assertOk()->json();
    }

    /** @return array<int, string> ang reason ng mga finding na may ganitong level */
    private function reasons(array $res, string $level): array
    {
        return array_values(array_map(fn ($f) => $f['reason'], array_filter($res['findings'], fn ($f) => $f['level'] === $level)));
    }

    public function test_S_106_1_the_expected_item_and_price_are_the_most_common_pair_of_the_last_3_days_of_orders(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 1, 0);
        $this->orders('Page Uno', 'SEAT COVER', '₱299.00', 1, 1);
        $this->orders(' page  uno ', 'SEAT COVER', '299', 1, 2);
        $this->orders('Page Uno', 'SEAT COVER', '499', 2, 0);
        $this->orders('Page Uno', 'PHONE HOLDER', '199', 5, 3); // apat na araw na: hindi kasama
        $this->orders('Page Dos', 'NIGHT LAMP', '₱1,299.00', 4, 0);
        $this->orders('Page Tres', 'GIFT', '0', 2, 0); // walang COD (hal. bayad na): walang presyong ihahambing

        $this->assertSame(['item' => 'SEAT COVER', 'price' => 299, 'source' => 'orders', 'orders' => 5], app(PageExpectedItem::class)->for('Page Uno'));
        $this->assertSame(['item' => 'NIGHT LAMP', 'price' => 1299, 'source' => 'orders', 'orders' => 4], app(PageExpectedItem::class)->for('Page Dos'));
        $this->assertSame(['item' => 'GIFT', 'price' => null, 'source' => 'orders', 'orders' => 2], app(PageExpectedItem::class)->for('Page Tres'));
    }

    public function test_S_106_2_without_recent_orders_the_latest_ads_item_then_the_item_settings_are_used_and_with_none_nothing_is_asked(): void
    {
        $this->orders('Page Uno', 'OLD ITEM', '299', 3, 5);
        DB::table('ads_manager_reports')->insert([
            ['day' => now()->subDays(10)->toDateString(), 'page_name' => 'Page Uno', 'item_name' => 'OLD AD ITEM'],
            ['day' => now()->subDays(2)->toDateString(), 'page_name' => 'page uno', 'item_name' => 'NIGHT LAMP'],
            ['day' => now()->subDay()->toDateString(), 'page_name' => 'Page Uno', 'item_name' => ''],
            ['day' => now()->toDateString(), 'page_name' => 'Page Dos', 'item_name' => 'OTHER'],
        ]);
        $expected = app(PageExpectedItem::class);
        $this->assertSame(['item' => 'NIGHT LAMP', 'price' => null, 'source' => 'ads', 'orders' => 0], $expected->for('Page Uno'));

        DB::table('ads_manager_reports')->delete();
        DB::table('page_item_settings')->insert([
            ['page_name' => 'Page Uno', 'item_name' => 'SEAT COVER', 'mode_cod_int' => 350, 'effective_date' => now()->subDays(50)->toDateString()],
            ['page_name' => 'Page Uno', 'item_name' => 'SEAT COVER V2', 'mode_cod_int' => 399, 'effective_date' => now()->subDays(20)->toDateString()],
            ['page_name' => 'Page Uno', 'item_name' => 'FUTURE', 'mode_cod_int' => 1, 'effective_date' => now()->addDays(5)->toDateString()],
        ]);
        $this->assertSame(['item' => 'SEAT COVER V2', 'price' => 399, 'source' => 'item_settings', 'orders' => 0], $expected->for('Page Uno'));

        DB::table('page_item_settings')->delete();
        $this->assertNull($expected->for('Page Uno'));
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover promo');
        $res = $this->content();
        $this->assertSame([null, [], 0], [$res['expected'], $res['findings'], $res['unchecked']]);
        $this->assertSame(0, $this->aiCalls());
    }

    public function test_S_106_3_another_item_is_an_x_a_synonym_passes_and_low_confidence_is_an_amber_warning(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Text about a clip holder');
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow_2', 'Text about a cushion cover');
        $this->settings()->set('Page Uno', 'chatbot', 'fill_up_form', 'Text about a light');
        $this->answers = [
            'Text about a clip holder' => $this->answer('FLEXIBLE CLIP PHONE HOLDER', false, 90),
            'Text about a cushion cover' => $this->answer('Car seat cushion cover', true, 85),
            'Text about a light' => $this->answer('LED STRIP', false, 55),
        ];
        $this->content();

        $res = $this->check();
        $this->assertSame('x', $res['status']);
        $this->assertSame(['Main Flow: FLEXIBLE CLIP PHONE HOLDER ≠ orders: SEAT COVER (90%)'], $res['reasons']);
        $this->assertSame(['Fill-Up Form: maybe LED STRIP, orders: SEAT COVER (55%)'], $res['warnings']);

        // Mababang kumpiyansa lang: babala, hindi X.
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow', 'Text about a light');
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow_2', 'Text about a cushion cover');
        $this->settings()->set('Page Dos', 'chatbot', 'fill_up_form', 'Text about a cushion cover');
        $this->content('Page Dos');
        $dos = $this->check('Page Dos');
        $this->assertSame(['ok', [], ['Main Flow: maybe LED STRIP, orders: SEAT COVER (55%)']], [$dos['status'], $dos['reasons'], $dos['warnings']]);
    }

    public static function prices(): array
    {
        return [
            'total equals the COD' => [[['total' => 299]], 299, []],
            'item price plus shipping equals the COD' => [[['item_price' => 249, 'shipping' => 50]], 299, []],
            'item price plus shipping and insurance' => [[['item_price' => 199, 'shipping' => 50, 'insurance' => 50]], 299, []],
            'item price alone equals the COD' => [[['item_price' => 299]], 299, []],
            'one of several options matches' => [[['total' => 499], ['total' => 299]], 299, []],
            'item price alone differs' => [[['item_price' => 199]], 299, ['Main Flow: price ₱199 ≠ orders ₱299 COD']],
            'item price plus shipping differs' => [[['item_price' => 199, 'shipping' => 50]], 299, ['Main Flow: price ₱199 + ₱50 shipping = ₱249 ≠ orders ₱299 COD']],
            'total differs' => [[['total' => 350]], 299, ['Main Flow: price ₱350 ≠ orders ₱299 COD']],
            'no price stated' => [[], 299, []],
            'no expected price (ads source)' => [[['item_price' => 199]], null, []],
        ];
    }

    #[DataProvider('prices')]
    public function test_S_106_4_the_price_compares_like_with_like_with_the_order_cod(array $prices, ?int $cod, array $x): void
    {
        if ($cod !== null) $this->orders('Page Uno', 'SEAT COVER', (string) $cod, 3);
        else DB::table('ads_manager_reports')->insert(['day' => now()->toDateString(), 'page_name' => 'Page Uno', 'item_name' => 'SEAT COVER']);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover {{PRICE}}');
        $this->settings()->set('Page Uno', 'chatbot', 'price', 'only today');
        // Ang {{PRICE}} ay pinupunan mula sa botfield bago itanong.
        $this->answers = ['Seat cover only today' => $this->answer('SEAT COVER', true, 95, $prices)];

        $this->assertSame($x, $this->reasons($this->content(), 'x'));
    }

    public function test_S_106_5_at_most_5_images_are_asked_picture_first_and_another_item_is_a_reason(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'picture', self::IMG . 'pic.jpg');
        for ($i = 1; $i <= 6; $i++) $this->settings()->set('Page Uno', 'chatbot', 'seq_img_' . $i, self::IMG . 'seq' . $i . '.jpg');
        $this->answers = [self::IMG . 'pic.jpg' => $this->answer('LED STRIP', false, 92)];
        for ($i = 1; $i <= 6; $i++) $this->answers[self::IMG . 'seq' . $i . '.jpg'] = $this->answer('SEAT COVER', true, 90);

        $res = $this->content();
        $asked = collect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com')))->map(fn ($pair) => data_get($pair[0]->data(), 'messages.1.content.1.image_url.url'))->all();
        $this->assertSame([self::IMG . 'pic.jpg', self::IMG . 'seq1.jpg', self::IMG . 'seq2.jpg', self::IMG . 'seq3.jpg', self::IMG . 'seq4.jpg'], $asked);
        $this->assertSame(['PICTURE: looks like LED STRIP, orders: SEAT COVER'], $this->reasons($res, 'x'));
        $this->assertContains('PICTURE: looks like LED STRIP, orders: SEAT COVER', $this->check()['reasons']);
    }

    public function test_S_106_6_a_video_linked_to_the_page_through_its_ad_copy_is_compared_and_an_unlinked_page_asks_nothing(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $xyz = DB::table('gpt_video_analyses')->insertGetId(['file_name' => 'xyz.mp4', 'file_sha256' => str_repeat('a', 64), 'item_name' => 'NIGHT LAMP', 'summary' => 'A warm lamp by the bed', 'updated_at' => now()]);
        $abc = DB::table('gpt_video_analyses')->insertGetId(['file_name' => 'abc.mp4', 'file_sha256' => str_repeat('b', 64), 'item_name' => 'SEAT COVER', 'summary' => 'Covers', 'updated_at' => now()]);
        DB::table('gpt_ad_generations')->insert([
            ['page_filter' => 'Other Page, page uno', 'video_analysis_id' => $xyz, 'created_at' => now()],
            ['page_filter' => 'Page Uno Extra', 'video_analysis_id' => $abc, 'created_at' => now()], // ibang page, hindi kahawig
            ['page_filter' => 'Page Uno', 'video_analysis_id' => null, 'created_at' => now()],
        ]);
        $this->answers = ['A warm lamp by the bed' => $this->answer('NIGHT LAMP', false, 95)];

        $res = $this->content();
        $this->assertSame(["Ad video 'xyz.mp4': NIGHT LAMP ≠ SEAT COVER"], $this->reasons($res, 'x'));
        $this->assertSame(1, $this->aiCalls());

        $dos = $this->content('Page Dos');
        $this->assertSame([[], 1], [$dos['findings'], $this->aiCalls()]);
    }

    public function test_S_106_7_an_answer_is_reused_for_the_same_version_and_expected_item_and_a_page_view_never_asks(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover text');
        $this->answers = ['Seat cover text' => $this->answer('SEAT COVER', true, 95), 'Seat cover text two' => $this->answer('SEAT COVER', true, 95)];

        // Ang page view: walang tanong, "not checked yet".
        $this->assertSame(1, $this->check()['content']['unchecked']);
        $this->getJson('/chatbot/data')->assertOk();
        $this->assertSame(0, $this->aiCalls());

        $this->content();
        $this->content();
        $this->assertSame(1, $this->aiCalls(), 'parehong version at item: mula sa naitala');
        $this->assertSame(0, $this->check()['content']['unchecked'], 'ang natapos na sagot ay lumalabas sa susunod na basa');

        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover text two');
        $this->assertSame(1, $this->content('Page Uno', false)['unchecked'], 'bagong version: wala pang sagot');
        $this->content();
        $this->assertSame(2, $this->aiCalls(), 'bagong version: tinanong ulit');

        $this->orders('Page Uno', 'PHONE HOLDER', '199', 5);
        $this->content();
        $this->assertSame(3, $this->aiCalls(), 'bagong inaasahang item: tinanong ulit');
    }

    public function test_S_106_8_the_command_queues_the_job_runs_it_with_sync_nightly_and_check_again_queues_one_page(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Uno text');
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow', 'Dos text');
        $this->answers = ['Uno text' => $this->answer('SEAT COVER', true, 95), 'Dos text' => $this->answer('SEAT COVER', true, 95)];

        $this->artisan('chatbot:content-check')->assertExitCode(0);
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === null);
        $this->artisan('chatbot:content-check', ['--page' => 'Page Dos'])->assertExitCode(0);
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === 'Page Dos');
        $this->assertSame(0, $this->aiCalls());

        $this->artisan('chatbot:content-check', ['--sync' => true])->assertExitCode(0);
        $this->assertSame(2, $this->aiCalls(), 'bawat page ng chatbot');
        $this->assertSame(2, DB::table('chatbot_content_answers')->count());

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'chatbot:content-check'));
        $this->assertCount(1, $events);
        $this->assertSame(['40 2 * * *', 'Asia/Manila'], [$events->first()->expression, $events->first()->timezone]);

        $this->actingAs($this->user());
        $this->postJson('/chatbot/check', ['page_name' => 'Page Uno'])->assertOk();
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === 'Page Uno');
    }

    public function test_S_106_9_the_check_json_and_the_command_json_carry_the_content_findings(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Uno text');
        $this->answers = ['Uno text' => $this->answer('NIGHT LAMP', false, 80)];
        $this->content();

        $res = $this->check();
        $this->assertSame(['item' => 'SEAT COVER', 'price' => 299, 'source' => 'orders', 'orders' => 3], $res['content']['expected']);
        $this->assertSame([['kind' => 'text', 'field' => 'Main Flow', 'level' => 'x', 'reason' => 'Main Flow: NIGHT LAMP ≠ orders: SEAT COVER (80%)']], $res['content']['findings']);
        $this->assertSame([0, []], [$res['content']['unchecked'], $res['warnings']]);

        Artisan::call('chatbot:content-check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(['Page Dos', 'Page Uno'], array_column($json['pages'], 'page'));
        $uno = collect($json['pages'])->firstWhere('page', 'Page Uno');
        $this->assertSame(['page', 'expected', 'findings', 'unchecked'], array_keys($uno));
        $this->assertSame($res['content']['findings'], $uno['findings']);
        $this->assertSame(1, $this->aiCalls(), '--json lang: walang bagong tanong');
    }

    public function test_S_106_10_each_answer_logs_model_tokens_and_cost_and_a_failed_answer_stores_nothing(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Good text');
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow_2', 'Down text');
        $this->settings()->set('Page Uno', 'chatbot', 'fill_up_form', 'Broken text');
        $this->settings()->set('Page Uno', 'chatbot', 'picture', self::IMG . 'pic.jpg');
        // Ang "true" na string ay tinatanggap (hindi itinatanong ulit gabi-gabi).
        $this->answers = ['Good text' => $this->answer('SEAT COVER', true, 95), 'Down text' => 503, 'Broken text' => 'not json at all',
            self::IMG . 'pic.jpg' => ['found_item' => 'SEAT COVER', 'same_product' => 'true', 'confidence' => '90']];

        $res = $this->content();
        $this->assertSame(2, $res['unchecked']);
        $rows = DB::table('chatbot_content_answers')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('ok', collect($res['findings'])->firstWhere('kind', 'image')['level']);
        $this->assertSame(['gpt-4o-mini', 1000, 100, 0.00021], [$rows[0]->model, (int) $rows[0]->tokens_in, (int) $rows[0]->tokens_out, round((float) $rows[0]->cost_usd, 6)]);
    }
}
