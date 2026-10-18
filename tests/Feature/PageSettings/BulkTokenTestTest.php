<?php

namespace Tests\Feature\PageSettings;

use App\Services\PageSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** "Test lahat ng token": tinatawag ng browser ang JSON endpoint na ito kada page, isa-isa. */
class BulkTokenTestTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-bulk-test-5r9k';

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->actingAs($this->user());
    }

    private function test(string $page)
    {
        return $this->postJson('/chatblast/page-settings/test-token/one', ['page_name' => $page]);
    }

    public static function answers(): array
    {
        return [
            'ok'            => [200, ['success' => true, 'data' => []], 'ok'],
            'success:false' => [200, ['success' => false], 'invalid'],
            '401'           => [401, ['message' => 'Unauthorized'], 'invalid'],
            '500'           => [500, ['message' => 'down'], 'error'],
            '429'           => [429, ['message' => 'slow down'], 'error'],
        ];
    }

    /** @dataProvider answers */
    public function test_one_page_is_tested_with_one_call_and_recorded_like_the_row_button(int $status, array $body, string $expected): void
    {
        Http::fake([
            'botcake.io/api/public_api/v1/pages/1001/custom_fields*' => Http::response($body, $status),
            'botcake.io/api/public_api/v1/pages/1001/flows*' => Http::response(['data' => ['flows' => []], 'success' => true]),
        ]);

        $res = $this->test(' page  UNO ')->assertOk()->assertJsonPath('status', $expected);

        // Walang retry (ang isang request ay hindi lalampas nang ~30 segundo). Kapag ok: binabasa rin ang mga flow, para sa
        // kusang setup ng flow (walang ibang flow sa fake, kaya walang templates call).
        Http::assertSentCount($expected === 'ok' ? 2 : 1);
        // Walang retry na paghihintay (2, 4, 8 s); ang pagitan lang ng rate limit (0.5 s) bago ang flows call.
        $this->assertSame([], array_filter($this->clock->sleeps, fn ($s) => $s > \App\Services\BotcakeClient::MIN_INTERVAL));
        $recorded = app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status');
        if ($expected === 'error') {
            $this->assertNull($recorded, 'ang error ay hindi itinatala, gaya ng row button');
            $this->assertNull($res->json('cell'));
            $this->assertSame('Botcake: HTTP ' . $status, $res->json('error'), 'walang "pagkatapos ng 0 retry"');
            // Ang 429 ng Botcake ay "rate_limited": maghihintay ang browser at susubok ulit nang isang beses.
            $this->assertSame($status === 429 ? 'rate_limited' : 'error', $res->json('reason'));
        } else {
            $this->assertStringStartsWith($expected . ' ', (string) $recorded);
            $this->assertSame($recorded, $res->json('cell.value'));
        }
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
        $this->assertStringNotContainsString('5r9k', $res->getContent());
    }

    public function test_a_page_without_id_or_token_is_skipped_without_a_call(): void
    {
        Http::fake();
        app(PageSettings::class)->set('Page Dos', 'botcake', 'page_id', '2002');

        $this->test('Page Dos')->assertOk()->assertJsonPath('status', 'skipped');
        $this->test('Page Wala')->assertOk()->assertJsonPath('status', 'skipped');

        Http::assertNothingSent();
    }

    public function test_testing_45_pages_does_not_use_up_the_limit_of_the_other_buttons(): void
    {
        Http::fake();
        Queue::fake();

        for ($i = 0; $i < 45; $i++) $this->test('Page Wala ' . $i)->assertOk();

        $this->postJson('/chatblast/page-settings/sync-all')->assertOk();
    }

    public function test_only_the_ceo_reaches_it(): void
    {
        Http::fake();
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->test('Page Uno')->assertNotFound();

        Http::assertNothingSent();
        $this->assertNull(app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status'));
    }
}
