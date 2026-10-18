<?php

namespace Tests\Feature\PageSettings;

use App\Jobs\SyncBotcakeCustomersJob;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** Ang Botcake buttons ng /page-settings: Test token at Sync now. */
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

        $res = $this->followingRedirects()->post('/chatblast/page-settings/test-token', ['page_name' => 'page uno'])->assertOk();

        Http::assertSentCount(1);
        $this->assertStringStartsWith($expected . ' ', (string) app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status'));
        $this->assertStringContainsString('Token: ' . $expected, $res->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
    }

    public function test_sync_now_queues_an_incremental_sync_for_that_page(): void
    {
        Queue::fake();

        $this->post('/chatblast/page-settings/sync', ['page_name' => 'Page Uno'])->assertRedirect();

        Queue::assertPushed(SyncBotcakeCustomersJob::class, fn ($job) => $job->page === 'Page Uno' && $job->full === false);
    }

    public function test_the_buttons_are_ceo_only(): void
    {
        Queue::fake();
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->post('/chatblast/page-settings/test-token', ['page_name' => 'Page Uno'])->assertNotFound();
        $this->post('/chatblast/page-settings/sync', ['page_name' => 'Page Uno'])->assertNotFound();
        Queue::assertNothingPushed();
    }
}
