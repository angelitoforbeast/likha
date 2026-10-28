<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\CompareMetaAds;
use App\Models\UploadLog;
use App\Services\MetaAds\Sync;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Ang mga ad account na binabasa ng Sync at Compare: pinipili ng CEO sa /ads_manager/report (o sa meta-ads:accounts). */
class MetaAdsAccountsTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    /** Marketing: 111, 222, 333; Marketing - OIC: 333, 444, 555 (5 account, ang 333 ay isang beses lang). */
    private function seedFive(): array
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222', '333']);
        $this->connect($ben, self::TOKEN_BEN, ['333', '444', '555']);

        return [$ana, $ben];
    }

    private function insightCalls(): array
    {
        $out = [];
        foreach ($this->graphCalls as $p) if (preg_match('#^act_(\d+)/insights$#', $p, $m)) $out[] = $m[1];

        return array_values(array_unique($out));
    }

    public function test_S_173_1_the_meta_section_lists_each_account_once_with_the_latest_compare_numbers_and_a_checkbox(): void
    {
        $this->seedFive();
        UploadLog::create([
            'type' => CompareMetaAds::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Compare', 'status' => 'done',
            'protected_details' => ['from' => self::DAY, 'to' => self::DAY, 'problems' => [], 'accounts' => [
                ['account_id' => '111', 'name' => 'Account 111', 'who' => 'x', 'api_rows' => 7, 'upload_rows' => 6, 'matched' => 6,
                    'api_spend' => 1234.5, 'upload_spend' => 1200.25, 'only_api_count' => 1, 'only_upload_count' => 0, 'differ_count' => 0,
                    'only_api' => [], 'only_upload' => [], 'differ' => []],
                ['account_id' => '444', 'name' => 'Account 444', 'who' => 'x', 'api_rows' => 0, 'upload_rows' => 0, 'matched' => 0,
                    'api_spend' => 0, 'upload_spend' => 0, 'only_api_count' => 0, 'only_upload_count' => 0, 'differ_count' => 0,
                    'only_api' => [], 'only_upload' => [], 'differ' => []],
            ]],
        ]);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $html = $this->get('/ads_manager/report')->assertOk()->getContent();

        foreach (['111', '222', '333', '444', '555'] as $id) {
            $this->assertSame(1, substr_count($html, 'name="accounts[]" value="' . $id . '"'), "checkbox ng {$id}");
        }
        $this->assertStringContainsString('Marketing User FB', substr($html, strpos($html, 'value="333"'), 600));
        $this->assertStringContainsString('Marketing - OIC User FB', substr($html, strpos($html, 'value="333"'), 600));
        $this->assertStringContainsString('upload 6 rows · ₱1,200.25', $html);
        $this->assertStringContainsString('API 7 rows · ₱1,234.50', $html);
        $this->assertStringContainsString('not in uploads', substr($html, strpos($html, 'value="444"'), 600));
    }

    public function test_S_173_1_a_ceo_saves_two_of_five_accounts_and_the_change_is_logged(): void
    {
        $this->seedFive();
        $ceo = $this->user('CEO', 'ceo@example.test');
        $this->actingAs($ceo);

        $this->post('/ads_manager/meta/accounts', ['accounts' => ['333', '111']])->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();

        $this->assertSame(['selected' => ['111', '333'], 'seen' => ['111', '222', '333', '444', '555']],
            json_decode(DB::table('app_settings')->where('key', Sync::ACCOUNTS)->value('value'), true));
        $event = UploadLog::where('type', 'ads_manager_api_event')->latest('id')->first();
        // Walang naka-save pa: lahat ay naka-check bago ang pili.
        $this->assertSame([$ceo->id, ['before' => ['111', '222', '333', '444', '555'], 'after' => ['111', '333']]], [(int) $event->user_id, $event->protected_details]);
        $this->assertStringContainsString('ad accounts', $event->original_name);
        $this->get('/ads_manager/report')->assertSee('value="111" checked', false)->assertDontSee('value="222" checked', false);
    }

    public function test_S_173_1_a_ceo_saving_with_nothing_ticked_clears_the_selection_and_it_is_logged(): void
    {
        $this->seedFive();
        Sync::setAccounts(['111', '222']);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $this->post('/ads_manager/meta/accounts', [])->assertSessionHasNoErrors();

        $this->assertSame([], Sync::accounts());
        $this->assertSame(['before' => ['111', '222'], 'after' => []], UploadLog::where('type', 'ads_manager_api_event')->latest('id')->first()->protected_details);
    }

    public function test_S_173_1_an_id_that_is_not_in_any_connection_is_refused(): void
    {
        $this->seedFive();
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $this->post('/ads_manager/meta/accounts', ['accounts' => ['111', '999']])->assertSessionHasErrors('meta');

        $this->assertFalse(DB::table('app_settings')->where('key', Sync::ACCOUNTS)->exists());
    }

    public static function marketing(): array
    {
        return ['Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC']];
    }

    #[DataProvider('marketing')]
    public function test_S_173_1_marketing_and_marketing_oic_can_save_the_selection(string $role): void
    {
        $this->seedFive();
        Sync::setAccounts(['111']);
        $this->actingAs($this->user($role, 'm@example.test'));

        $this->get('/ads_manager/report')->assertSee('value="111" checked', false)->assertDontSee('value="111" checked disabled', false)->assertSee('Save accounts');
        $this->post('/ads_manager/meta/accounts', ['accounts' => ['222']])->assertSessionHasNoErrors();

        $this->assertSame(['222'], Sync::accounts());
    }

    public function test_S_173_1_another_role_with_the_ads_link_sees_the_list_read_only_and_the_save_is_refused(): void
    {
        $this->seedFive();
        Sync::setAccounts(['111']);
        $link = DB::table('nav_links')->where('route_url', '/ads_manager/report')->value('id');
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $link, 'role' => 'Admin'], ['is_visible' => true]);
        $this->actingAs($this->user('Admin', 'admin@example.test'));

        $this->get('/ads_manager/report')->assertSee('value="111" checked disabled', false)->assertDontSee('Save accounts');
        $this->post('/ads_manager/meta/accounts', ['accounts' => ['222']])->assertForbidden();

        $this->assertSame(['111'], Sync::accounts());
    }

    public function test_S_173_2_sync_with_no_selection_makes_no_graph_calls_and_says_choose_the_accounts_first(): void
    {
        Sync::setWriteMode(true);
        [$ana] = $this->seedFive();
        Sync::setAccounts([]); // bawat account ay naka-uncheck
        $this->fakeGraph();
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync')->assertRedirect('/ads_manager/report')
            ->assertSessionHasErrors(['meta' => 'Choose the ad accounts first']);
        $this->artisan('meta-ads:sync')->expectsOutputToContain('Choose the ad accounts first')->assertSuccessful();
        $this->artisan('meta-ads:sync', ['--scheduled' => true])->doesntExpectOutputToContain('Choose')->assertSuccessful();
        $this->assertFalse(DB::table('upload_logs')->where('type', Sync::LOG_TYPE)->exists());
        $log = app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);

        $this->assertSame([], $this->graphCalls);
        $this->assertSame(['failed', 'Choose the ad accounts first'], [$log->status, $log->error_message]);
    }

    public function test_S_173_2_sync_reads_only_the_selected_accounts(): void
    {
        Sync::setWriteMode(true);
        $this->seedFive();
        Sync::setAccounts(['222', '444']);
        $this->fakeGraph();

        $log = app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);

        $this->assertSame('done', $log->status);
        $this->assertSame(['222', '444'], $this->insightCalls());
    }

    public static function compareSelections(): array
    {
        return [
            'every account unticked: all'    => [[], ['111', '222', '333', '444', '555']],
            'nothing saved yet: all ticked'  => [null, ['111', '222', '333', '444', '555']],
            'a selection: only those'        => [['333', '555'], ['333', '555']],
        ];
    }

    #[DataProvider('compareSelections')]
    public function test_S_173_2_compare_reads_the_selected_accounts_or_all_while_nothing_is_selected(?array $selected, array $read): void
    {
        $this->seedFive();
        if ($selected !== null) Sync::setAccounts($selected);
        $this->fakeGraph();

        $this->artisan('meta-ads:compare', ['--from' => self::DAY, '--to' => self::DAY])->assertSuccessful();

        $this->assertSame($read, $this->insightCalls());
    }

    public function test_S_173_3_the_accounts_command_refuses_an_unknown_id_sets_valid_ones_and_lists_without_tokens(): void
    {
        $this->seedFive();

        $this->artisan('meta-ads:accounts', ['--set' => '111,999'])->expectsOutputToContain('999')->assertFailed();
        $this->assertFalse(DB::table('app_settings')->where('key', Sync::ACCOUNTS)->exists());

        $this->artisan('meta-ads:accounts', ['--set' => '555, 111'])->expectsOutputToContain('2 of 5')->assertSuccessful();
        $this->assertSame(['111', '555'], Sync::accounts());
        $this->assertSame(['before' => ['111', '222', '333', '444', '555'], 'after' => ['111', '555']], UploadLog::where('type', 'ads_manager_api_event')->latest('id')->first()->protected_details);

        $this->artisan('meta-ads:accounts', ['--list' => true])
            ->expectsOutputToContain('111  Account 111  yes')
            ->expectsOutputToContain('222  Account 222  no')
            ->doesntExpectOutputToContain(self::TOKEN_ANA)
            ->doesntExpectOutputToContain(self::TOKEN_BEN)
            ->assertSuccessful();
    }

    public function test_S_173_1_a_new_account_is_ticked_by_default_and_an_unticked_one_stays_off(): void
    {
        Sync::setWriteMode(true);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222', '333', '444']);
        $this->artisan('meta-ads:accounts', ['--set' => '111,222'])->assertSuccessful();

        // Lumabas ang 555 sa connection (Reconnect): bago, kaya naka-check.
        $this->connect($ana, self::TOKEN_ANA, ['111', '222', '333', '444', '555']);
        $this->fakeGraph();
        app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);
        $this->assertSame(['111', '222', '555'], $this->insightCalls());
        $this->assertSame(['selected' => ['111', '222', '555'], 'seen' => ['111', '222', '333', '444', '555']],
            json_decode(DB::table('app_settings')->where('key', Sync::ACCOUNTS)->value('value'), true));

        // Inalis ang check ng 555: hindi na binabasa.
        $this->artisan('meta-ads:accounts', ['--set' => '111,222'])->assertSuccessful();
        $this->graphCalls = [];
        app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);
        $this->assertSame(['111', '222'], $this->insightCalls());
    }

    public function test_S_173_1_the_old_plain_list_value_still_reads_as_the_selection(): void
    {
        $this->seedFive();
        DB::table('app_settings')->insert(['key' => Sync::ACCOUNTS, 'value' => json_encode(['333', '111']), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['111', '333'], Sync::accounts());
    }
}
