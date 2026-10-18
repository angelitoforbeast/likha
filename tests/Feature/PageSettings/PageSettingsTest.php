<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** /page-settings: Page | Provider | Key | Value, CEO lang. */
class PageSettingsTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-test-abcdef-9f3k';

    private function save(array $input)
    {
        return $this->post('/chatblast/page-settings', $input + ['provider' => 'botcake']);
    }

    public function test_a_new_value_replaces_the_active_row_and_keeps_the_old_one_as_history(): void
    {
        $this->actingAs($ceo = $this->user());

        $this->save(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '1111'])->assertRedirect();
        $this->save(['page_name' => ' page uno ', 'key' => 'page_id', 'value' => '2222'])->assertRedirect();

        $rows = PageSetting::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([false, true], $rows->pluck('active')->map(fn ($v) => (bool) $v)->all());
        $this->assertNotNull($rows[0]->replaced_at);
        $this->assertSame('Page Uno', $rows[1]->page_name, 'ang pangalan ng page ay ang naunang naitala (case-insensitive)');
        $this->assertSame('2222', app(PageSettings::class)->get('PAGE UNO', 'botcake', 'page_id'));
        $this->assertSame($ceo->id, (int) $rows[1]->created_by);

        // Parehong value ulit → walang bagong row.
        $this->save(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '2222']);
        $this->assertSame(2, PageSetting::count());
    }

    public function test_the_token_is_encrypted_at_rest_and_never_in_a_response_or_a_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });

        $this->actingAs($this->user());
        $this->save(['page_name' => 'Page Uno', 'key' => 'token', 'value' => self::TOKEN])->assertRedirect();

        $raw = DB::table('page_settings')->value('value');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, Crypt::decryptString($raw));
        $this->assertTrue((bool) PageSetting::sole()->is_secret, 'ang token ay laging secret');

        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('tok-test', $html);
        $this->assertStringContainsString('9f3k', $html, 'huling 4 na character lang ang nakikita');
        $this->assertStringNotContainsString(self::TOKEN, json_encode(PageSetting::sole()->toArray()));

        $this->assertNotEmpty($logged, 'naitala ang pagbabago');
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logged));
    }

    public static function values(): array
    {
        return [
            'page id na may slash'   => ['page_id', '442139078/', false],
            'page id'                => ['page_id', '442139078', true],
            'walang laman na token'  => ['token', '   ', false],
            'flow na hindi digits'   => ['flow.on_delivery', '12a', false],
            'flow'                   => ['flow.on_delivery', '55501', true],
            'field na hindi digits'  => ['field.waybill', 'abc', false],
            'field'                  => ['field.waybill', '901', true],
            'token_status ng system' => ['token_status', 'ok', false],
            'malayang key'           => ['greeting_text', 'Salamat po!', true],
        ];
    }

    /** @dataProvider values */
    public function test_known_keys_are_validated_and_free_keys_are_accepted(string $key, string $value, bool $ok): void
    {
        $this->actingAs($this->user());

        $res = $this->save(['page_name' => 'Page Uno', 'key' => $key, 'value' => $value]);

        if ($ok) {
            $res->assertSessionHasNoErrors();
            $this->assertSame(1, PageSetting::count());
        } else {
            $res->assertSessionHasErrors('value');
            $this->assertSame(0, PageSetting::count());
        }
    }

    public function test_a_free_key_marked_secret_is_encrypted(): void
    {
        $this->actingAs($this->user());

        $this->save(['page_name' => 'Page Uno', 'provider' => 'sms', 'key' => 'api_key', 'value' => 'sms-secret-77', 'is_secret' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNotSame('sms-secret-77', DB::table('page_settings')->value('value'));
        $this->assertSame('sms-secret-77', app(PageSettings::class)->get('Page Uno', 'sms', 'api_key'));
    }

    public function test_deactivate_keeps_the_row_as_history(): void
    {
        $this->actingAs($this->user());
        $this->save(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '1111']);
        $id = PageSetting::sole()->id;

        $this->post("/chatblast/page-settings/{$id}/deactivate")->assertRedirect();

        $row = PageSetting::sole();
        $this->assertFalse((bool) $row->active);
        $this->assertNotNull($row->replaced_at);
        $this->assertNull(app(PageSettings::class)->get('Page Uno', 'botcake', 'page_id'));
        $this->assertSame('1111', $this->getJson('/chatblast/page-settings/history?page_name=Page%20Uno&provider=botcake&key=page_id')->json('history.0.value'), 'nasa history pa rin');
    }

    public function test_a_role_checked_in_nav_settings_reaches_the_pages_and_others_do_not(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        $link = \Illuminate\Support\Facades\DB::table('nav_links')->insertGetId([
            'key' => 'page_settings_test', 'label' => 'Page Settings', 'route_url' => '/page-settings', 'is_active' => true,
            'sort_order' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['Marketing - OIC' => true, 'Marketing' => true, 'Data Encoder' => false] as $role => $visible) {
            \Illuminate\Support\Facades\DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $link, 'role' => $role, 'is_visible' => $visible, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->get('/chatblast/page-settings')->assertOk();
        $this->get('/chatblast/page-settings/customers?page=Page%20Uno')->assertOk();
        $this->get('/chatblast/page-settings/psid-coverage')->assertOk();

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatblast/page-settings')->assertOk();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatblast/page-settings')->assertNotFound();
        $this->get('/chatblast/page-settings/customers?page=Page%20Uno')->assertNotFound();

        // naka-uncheck ulit: sarado na
        \Illuminate\Support\Facades\DB::table('nav_link_role_visibility')->where('role', 'Marketing')->update(['is_visible' => false]);
        $this->actingAs($this->user('Marketing', 'mkt2@example.test'));
        $this->get('/chatblast/page-settings')->assertNotFound();
    }

    public function test_only_the_ceo_reaches_the_page(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->get('/chatblast/page-settings')->assertNotFound();
        $this->save(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '1111'])->assertNotFound();
        $this->assertSame(0, PageSetting::count());
    }
}
