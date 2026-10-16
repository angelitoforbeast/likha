<?php

namespace Tests\Feature\PageSettings;

use App\Jobs\SyncBotcakeCustomersJob;
use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** Ang Botcake na bahagi ng /page-settings: Test token, Sync now, at ang orders na wala pang PSID. */
class BotcakePanelTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-panel-test-8h2w';

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->actingAs($this->user());
    }

    public static function tokenAnswers(): array
    {
        return [
            'ok'           => [200, ['success' => true, 'data' => []], 'ok'],
            'success:false' => [200, ['success' => false], 'invalid'],
            '401'          => [401, ['message' => 'Unauthorized'], 'invalid'],
        ];
    }

    /** @dataProvider tokenAnswers */
    public function test_test_token_makes_one_custom_fields_call_and_records_ok_or_invalid(int $status, array $body, string $expected): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/custom_fields*' => Http::response($body, $status)]);

        $res = $this->followingRedirects()->post('/page-settings/test-token', ['page_name' => 'page uno'])->assertOk();

        Http::assertSentCount(1);
        $this->assertStringStartsWith($expected . ' ', (string) app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status'));
        $this->assertStringContainsString('Token: ' . $expected, $res->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
    }

    public function test_sync_now_queues_an_incremental_sync_for_that_page(): void
    {
        Queue::fake();

        $this->post('/page-settings/sync', ['page_name' => 'Page Uno'])->assertRedirect();

        Queue::assertPushed(SyncBotcakeCustomersJob::class, fn ($job) => $job->page === 'Page Uno' && $job->full === false);
    }

    public function test_the_panel_shows_customers_the_last_sync_and_links_to_the_orders_without_a_psid(): void
    {
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ben Reyes', 'psid' => 'b1', 'status' => 'available', 'source' => 'api']);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ben Reyes', 'psid' => 'b2', 'status' => 'available', 'source' => 'api']);
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'batches_read' => 3, 'customers_new' => 21, 'matched_by_name' => 4]);
        $this->order('Page Uno', 'Ben Reyes');
        $this->order('Page Uno', 'Dino Lim');

        $html = $this->get('/page-settings')->assertOk()->getContent();
        $this->assertStringContainsString('Customers: 2', $html);
        $this->assertStringContainsString('21 bago', $html);
        $this->assertStringNotContainsString('Dino Lim', $html, 'ang listahan ay nasa psid-coverage, paginated');
        $this->assertStringContainsString('/page-settings/psid-coverage/orders?page_name=Page%20Uno', $html);
    }

    public function test_the_buttons_are_ceo_only(): void
    {
        Queue::fake();
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->post('/page-settings/test-token', ['page_name' => 'Page Uno'])->assertNotFound();
        $this->post('/page-settings/sync', ['page_name' => 'Page Uno'])->assertNotFound();
        Queue::assertNothingPushed();
    }
}
