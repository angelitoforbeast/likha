<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Ang naka-cache na settings ng page (spec 098): ang get() ay mula sa cache (10 minuto) at sa maikling memo ng request,
 * hindi sa MySQL bawat tawag; binubura sa bawat sulat; ang secret ay naka-encrypt pa rin sa cache.
 */
class PageSettingsCacheTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Ilang query sa page_settings habang tumatakbo ang $fn. */
    private function settingsQueries(\Closure $fn): int
    {
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'page_settings')) $n++;
        });
        $fn();

        return $n;
    }

    /** Bagong request o job: ang memo ay nawawala, ang cache ay naiiwan. */
    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
    }

    public function test_S_098_1_a_miss_reads_mysql_once_then_every_read_is_a_hit_from_the_memo_or_the_cache(): void
    {
        $this->nextRequest();
        Cache::flush();
        $this->assertSame(1, $this->settingsQueries(function () {
            $this->assertSame('1001', $this->settings()->get('Page Uno', 'botcake', 'page_id'));
            $this->assertSame(self::TOKEN, $this->settings()->get(' page  uno ', 'botcake', 'token'));
            $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'flow_id'));
        }), 'one query for the whole page');

        $this->nextRequest();
        $this->assertSame(0, $this->settingsQueries(fn () => $this->assertSame('1001', $this->settings()->get('Page Uno', 'botcake', 'page_id'))), 'next request: from the cache');
    }

    public function test_S_098_2_the_cache_holds_the_secret_encrypted_never_the_plain_token(): void
    {
        $this->settings()->get('Page Uno', 'botcake', 'token');
        $stored = serialize(Cache::get(PageSettings::cacheKey('Page Uno')));

        $this->assertStringContainsString('1001', $stored, 'the page map is cached');
        $this->assertStringNotContainsString(self::TOKEN, $stored);
    }

    public static function writes(): array
    {
        return [
            'cell' => [function (self $t) {
                $t->actingAs($t->user());
                $t->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'price', 'value' => '599'])->assertOk();
            }, 'chatbot', 'price', '599'],
            'bulk save and paste' => [function (self $t) {
                $t->actingAs($t->user());
                $t->postJson('/chatbot/cells', ['changes' => [['page_name' => 'Page Uno', 'key' => 'price', 'value' => '599']]])->assertOk();
            }, 'chatbot', 'price', '599'],
            'deactivate' => [function (self $t) {
                $t->actingAs($t->user());
                $id = PageSetting::where('key', 'price')->where('active', true)->value('id');
                $t->post('/chatblast/page-settings/' . $id . '/deactivate')->assertRedirect();
            }, 'chatbot', 'price', null],
            'setup check' => [function () {
                app(ChatblastSetupCheck::class)->recordFailure('Page Uno', 'Botcake did not answer');
            }, 'botcake', ChatblastSetupCheck::CHECK_KEY, 'json'],
            'model write' => [function () {
                PageSetting::where('key', 'price')->where('active', true)->first()->update(['value' => '599']);
            }, 'chatbot', 'price', '599'],
        ];
    }

    /** @dataProvider writes */
    public function test_S_098_3_every_write_forgets_the_cached_page_so_the_edit_applies_at_once(\Closure $write, string $provider, string $key, ?string $expected): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->assertSame('499', $this->settings()->get('Page Uno', 'chatbot', 'price'));
        $this->assertNull($this->settings()->get('Page Uno', 'botcake', ChatblastSetupCheck::CHECK_KEY));

        $write($this);

        $this->nextRequest();
        $value = $this->settings()->get('Page Uno', $provider, $key);
        $expected === 'json' ? $this->assertJson((string) $value) : $this->assertSame($expected, $value);
    }
}
