<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidSetting;
use App\Models\PageSetting;
use App\Services\BotcakeCustomerMatcher;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakePsidMatcher;
use App\Services\PageSettings;
use App\Jobs\HandleGatewayEvent;
use App\Models\ChatbotReply;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\ChatbotTags;
use App\Services\Chatbot\Inbox;
use App\Services\Chatbot\LiveFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Chatblast\ChatblastTestCase;

/** Ang page_id ng botcake_psids: isinusulat ng bawat writer, at ito muna ang tinitingnan ng mga reader (pangalan kapag wala). */
class BotcakePsidPageIdTest extends ChatblastTestCase
{
    private const INFO = 'botcake.io/api/public_api/v1/pages/*/customer/*/info';

    protected bool $withCampaigns = false;

    /** Ang mga table ng chat at ng Inbox, para sa mga reader ng chat. */
    private function chatTables(): void
    {
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
    }

    /** Ang pangalang nakikita ng reader ng chat para sa customer na walang pangalan sa chat (null = wala). */
    private function chatName(string $reader, string $pageId, string $page, string $psid): ?string
    {
        if ($reader === 'gateway') {
            $settings = app(PageSettings::class);
            return (fn () => $this->name($settings, $page, ['page_id' => $pageId, 'psid' => $psid]))->call(new HandleGatewayEvent([]));
        }
        if ($reader === 'inboxList') {
            ChatbotReply::where('psid', (int) $psid)->delete();
            ChatbotReply::create(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'node_id' => 'n1', 'kind' => 'send', 'content' => ['text' => 'Hi'], 'status' => 'sent', 'sent_at' => now()]);
            $name = (string) (app(Inbox::class)->listWithCounts($pageId)['rows'][0]['name'] ?? '');
            return $name === '' || str_starts_with($name, 'PSID') ? null : $name;
        }
        if ($reader === 'inboxPanel') {
            $name = (string) app(Inbox::class)->panel($pageId, $page, $psid)['name'];
            return str_starts_with($name, 'PSID') ? null : $name;
        }
        // liveFeed
        $top = ['bot' => [$pageId . '|' . $psid => now()->toDateTimeString()]];
        $start = now()->startOfDay()->toDateTimeString();
        $end = now()->endOfDay()->toDateTimeString();
        $names = (fn () => $this->customerFacts($top, $start, $end, [$pageId => $page])[0])->call(app(LiveFeed::class));
        $name = (string) ($names[$pageId . '|' . $psid] ?? '');
        return $name === '' || str_starts_with($name, 'Customer') ? null : $name;
    }

    private function pageId(string $page, string $id): void
    {
        app(PageSettings::class)->set($page, 'botcake', 'page_id', $id);
    }

    /** Pinalitan ang pangalan ng page sa page settings: ang lumang pangalan ay hindi na active, ang bago ay may parehong id. */
    private function rename(string $from, string $to): void
    {
        $settings = app(PageSettings::class);
        foreach (PageSetting::where('page_name', $from)->where('active', true)->get() as $s) {
            $settings->set($to, $s->provider, $s->key, (string) $s->plainValue(), (bool) $s->is_secret);
            $settings->deactivate($s);
        }
    }

    private function psid(string $page, ?string $pageId, string $psid, string $name, array $extra = []): BotcakePsid
    {
        return BotcakePsid::create($extra + ($pageId === null ? [] : ['page_id' => $pageId]) + [
            'page' => $page, 'fb_name' => $name, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
        ]);
    }

    private function sheetSetting(): BotcakePsidSetting
    {
        return BotcakePsidSetting::create(['gsheet_name' => 'T', 'sheet_url' => 'https://docs.google.com/spreadsheets/d/T1/edit', 'sheet_range' => 'PSID!A:J']);
    }

    /** Ang customer list ng Botcake para sa page id 1001: iisang batch, saka wala na. */
    private function fakeBotcake(array $customers): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/customer*' => function ($req) use ($customers) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            return Http::response((int) ($q['page'] ?? 0) === 1 ? $customers : []);
        }]);
    }

    public static function syncCases(): array
    {
        return ['new rows' => ['new'], 'an older row without a page id gets it' => ['older'], 'after a rename the old row is updated, not doubled' => ['renamed']];
    }

    #[DataProvider('syncCases')]
    public function test_S_233_1_2_the_api_sync_stores_the_page_id_from_settings(string $case): void
    {
        $page = $case === 'renamed' ? 'Page Bago' : 'Page Uno';
        $this->pageId('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'token', self::TOKEN);
        if ($case === 'older') $this->psid('Page Uno', null, 'p1', 'Old Name');
        if ($case === 'renamed') {
            $this->psid('Page Uno', '1001', 'p1', 'Old Name');
            $this->rename('Page Uno', 'Page Bago');
        }
        $this->fakeBotcake([$this->customer('p1', 'Ana Cruz'), $this->customer('p2', 'Ben Reyes')]);

        app(BotcakeCustomerSync::class)->syncPage($page, true);

        $this->assertSame(['p1' => '1001', 'p2' => '1001'], BotcakePsid::orderBy('psid')->pluck('page_id', 'psid')->all());
        $this->assertSame(1, BotcakePsid::where('psid', 'p1')->count(), 'isang row kada PSID');
        $this->assertSame('Ana Cruz', BotcakePsid::where('psid', 'p1')->value('fb_name'));
        if ($case !== 'new') $this->assertSame('Page Uno', BotcakePsid::where('psid', 'p1')->value('page'), 'hindi pinapalitan ang page');
    }

    public static function noIdCases(): array
    {
        return ['a page without botcake.page_id' => ['no id'], 'before the column is migrated' => ['no column']];
    }

    #[DataProvider('noIdCases')]
    public function test_S_233_1_3_a_page_without_an_id_still_stores_its_row(string $case): void
    {
        if ($case === 'no column') {
            $this->pageId('Page Uno', '1001');
            (require base_path('database/migrations/2026_11_18_100000_add_page_id_to_botcake_psids.php'))->down();
            Once::flush();
            $this->psid('Page Uno', null, 'psid-api', 'Ana Cruz');
        }
        $order = $this->order('Page Uno', 'Ana Cruz');

        $status = app(BotcakePsidMatcher::class)->recordSheetRow($this->sheetSetting(), 'Page Uno', 'Ana Cruz', 'psid-sheet', '2026-11-05');

        $this->assertSame('matched', $status);
        $this->assertSame('psid-sheet', $order->fresh()->botcake_psid);
        $row = BotcakePsid::where('psid', 'psid-sheet')->sole();
        $this->assertNull($row->getAttributes()['page_id'] ?? null);
        if ($case === 'no column') {
            // Ang mga reader ay pangalan ang gamit gaya ng dati.
            $other = $this->order('Page Uno', 'Ana Cruz', null, null, now('Asia/Manila')->subDay()->toDateString());
            \Illuminate\Support\Facades\DB::table('macro_output')->where('id', $other->id)->update(['botcake_psid' => null]);
            BotcakePsid::where('psid', 'psid-sheet')->delete();
            app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');
            $this->assertSame('psid-api', $other->fresh()->botcake_psid);
        }
    }

    public static function renameReaders(): array
    {
        return [
            'BotcakeCustomerMatcher' => ['customerMatcher'],
            'BotcakePsidMatcher'     => ['psidMatcher'],
            'HandleGatewayEvent name' => ['gatewayName'],
            'BotcakeChatGuard check'  => ['guardCheck'],
            'Inbox list name'         => ['inboxListName'],
            'Inbox panel'             => ['inboxPanelName'],
            'LiveFeed name'           => ['liveFeedName'],
            'ChatbotTags ad'          => ['tagsAd'],
        ];
    }

    /** Page "Page Uno" (id 1001) ay may mga row; pinalitan ng "Page Bago" sa page settings. */
    #[DataProvider('renameReaders')]
    public function test_S_233_3_1_a_renamed_page_still_finds_its_old_rows(string $reader): void
    {
        $this->pageId('Page Uno', '1001');
        $this->rename('Page Uno', 'Page Bago');

        $this->{$reader . 'AfterRename'}();
    }

    private function customerMatcherAfterRename(): void
    {
        $this->psid('Page Uno', '1001', 'psid-a', 'Ana Cruz', ['ad_id' => '120250000000000001']);
        $byName = $this->order('Page Bago', 'Ana Cruz');
        $hasPsid = $this->order('Page Bago', 'Old Buyer', null, 'psid-a');

        app(BotcakeCustomerMatcher::class)->matchPage('Page Bago');

        $this->assertSame('psid-a', $byName->fresh()->botcake_psid);
        $this->assertSame('120250000000000001', $hasPsid->fresh()->botcake_ad_id, 'ang ad id ng lumang row ay nakikita rin');
    }

    private function psidMatcherAfterRename(): void
    {
        // Ang lumang sheet record ng customer ay may ibang PSID: ang bagong row ay conflict, hindi ikinakabit.
        $this->psid('Page Uno', '1001', 'psid-old', 'Ana Cruz', ['source' => 'sheet', 'status' => 'matched']);
        $order = $this->order('Page Bago', 'Ana Cruz');

        $status = app(BotcakePsidMatcher::class)->recordSheetRow($this->sheetSetting(), 'Page Bago', 'Ana Cruz', 'psid-new', '2026-11-05');

        $this->assertSame('conflict', $status);
        $this->assertNull($order->fresh()->botcake_psid);
        $this->assertSame('1001', BotcakePsid::where('psid', 'psid-new')->value('page_id'), 'ang bagong sheet row ay may page id');
    }

    private function oldChatRow(): void
    {
        $this->chatTables();
        $this->psid('Page Uno', '1001', '7001', 'Ana Cruz', ['source' => 'chat_verify', 'status' => 'verified', 'ad_id' => '120250000000000001']);
    }

    private function gatewayNameAfterRename(): void
    {
        $this->oldChatRow();
        $this->assertSame('Ana Cruz', $this->chatName('gateway', '1001', 'Page Bago', '7001'));
    }

    private function guardCheckAfterRename(): void
    {
        $this->oldChatRow();
        Http::fake(); // walang tawag sa Botcake: kilala na ang PSID sa page

        $this->assertSame('ok', app(BotcakeChatGuard::class)->checkPsid('Page Bago', '1001', '7001', 'Ana Cruz'));
        Http::assertNothingSent();
    }

    private function inboxListNameAfterRename(): void
    {
        $this->oldChatRow();
        $this->assertSame('Ana Cruz', $this->chatName('inboxList', '1001', 'Page Bago', '7001'));
    }

    private function inboxPanelNameAfterRename(): void
    {
        $this->oldChatRow();
        $this->assertSame('Ana Cruz', $this->chatName('inboxPanel', '1001', 'Page Bago', '7001'));
    }

    private function liveFeedNameAfterRename(): void
    {
        $this->oldChatRow();
        $this->assertSame('Ana C.', $this->chatName('liveFeed', '1001', 'Page Bago', '7001'));
    }

    private function tagsAdAfterRename(): void
    {
        $this->oldChatRow();
        $this->assertSame('120250000000000001', ChatbotTags::adId(1001, 7001, 'Page Bago'));
    }

    public function test_S_233_1_1_a_verified_chat_stores_its_psid_row_with_the_page_id(): void
    {
        $this->chatTables();
        $this->page('Page Uno', '1001');
        Http::fake([self::INFO => Http::response(['success' => true, 'data' => ['psid' => '7001', 'id' => 'c-1']])]);

        $this->assertSame('ok', app(BotcakeChatGuard::class)->checkPsid('Page Uno', '1001', '7001', 'Ana Cruz'));

        $row = BotcakePsid::where('psid', '7001')->sole();
        $this->assertSame(['chat_verify', 'Page Uno', '1001'], [$row->source, $row->page, $row->page_id]);
    }

    public static function samePageNameCases(): array
    {
        return [
            'customer matcher candidates' => ['customerMatcher'],
            'sheet records' => ['psidMatcher'],
            'the sync marks only its own rows inactive' => ['inactive'],
            'chat readers' => ['chatReaders'],
        ];
    }

    /** Ang "Shop" ay dating page id 1001 (ngayon "Moved"), at ngayon ay page id 2002: may row ang dalawa sa "Shop". */
    #[DataProvider('samePageNameCases')]
    public function test_S_233_3_2_two_pages_with_the_same_name_find_only_their_own_rows(string $case): void
    {
        $this->pageId('Shop', '1001');
        $this->rename('Shop', 'Moved');
        $this->pageId('Shop', '2002');

        $this->{$case . 'SameName'}();
    }

    private function customerMatcherSameName(): void
    {
        $this->psid('Shop', '1001', 'x1', 'Ana Cruz');
        $this->psid('Shop', '2002', 'y1', 'Ana Cruz');
        // Ang chat record ng parehong page ay hindi kandidato (API rows lang).
        $this->psid('Shop', '2002', 'y2', 'Ana Cruz', ['source' => 'chat_verify', 'status' => 'verified']);
        $shop = $this->order('Shop', 'Ana Cruz');
        $moved = $this->order('Moved', 'Ana Cruz');

        $m = app(BotcakeCustomerMatcher::class);
        $m->matchPage('Shop');
        $m->matchPage('Moved');

        $this->assertSame('y1', $shop->fresh()->botcake_psid);
        $this->assertSame('x1', $moved->fresh()->botcake_psid);
    }

    private function psidMatcherSameName(): void
    {
        // Ang sheet record ng ibang page (1001) na kapangalan ay hindi conflict ng page 2002.
        $this->psid('Shop', '1001', 'x-old', 'Ana Cruz', ['source' => 'sheet', 'status' => 'matched']);
        $order = $this->order('Shop', 'Ana Cruz');

        $status = app(BotcakePsidMatcher::class)->recordSheetRow($this->sheetSetting(), 'Shop', 'Ana Cruz', 'y-new', '2026-11-05');

        $this->assertSame('matched', $status);
        $this->assertSame('y-new', $order->fresh()->botcake_psid);
    }

    private function chatReadersSameName(): void
    {
        $this->chatTables();
        $this->page('Shop', '2002');
        // Ang PSID 7001 ay naitala sa "Shop" ng page 1001 lang.
        $this->psid('Shop', '1001', '7001', 'Xavier Lim', ['source' => 'chat_verify', 'status' => 'verified', 'ad_id' => '120250000000000009']);
        Http::fake([self::INFO => Http::response(['success' => false, 'message' => 'Not found customer'])]);

        foreach (['gateway', 'inboxList', 'inboxPanel', 'liveFeed'] as $reader) {
            $this->assertNull($this->chatName($reader, '2002', 'Shop', '7001'), $reader);
        }
        $this->assertNull(ChatbotTags::adId(2002, 7001, 'Shop'));
        $this->assertNotSame('ok', app(BotcakeChatGuard::class)->checkPsid('Shop', '2002', '7001', 'Xavier Lim'));
        $this->assertSame('Xavier Lim', $this->chatName('inboxPanel', '1001', 'Moved', '7001'), 'ang sariling page ay nakikita pa rin');
    }

    private function inactiveSameName(): void
    {
        $stale = ['last_seen_at' => now()->subDays(40), 'subscribed_at' => now()->subDays(20)];
        $other = $this->psid('Shop', '1001', 'x1', 'Ana Cruz', $stale);
        $own = $this->psid('Shop', '2002', 'y1', 'Ben Reyes', $stale);
        $unknown = $this->psid('Shop', null, 'z1', 'Carla Uy', $stale); // walang page id pa: ayon sa pangalan, gaya ng dati
        app(PageSettings::class)->set('Shop', 'botcake', 'token', self::TOKEN);
        Http::fake(['botcake.io/api/public_api/v1/pages/2002/customer*' => Http::response([])]);

        app(BotcakeCustomerSync::class)->syncPage('Shop', true);

        $this->assertSame(['available', 'inactive', 'inactive'], [$other->fresh()->status, $own->fresh()->status, $unknown->fresh()->status]);
    }

    public function test_S_233_3_3_rows_without_a_page_id_still_match_by_name(): void
    {
        $this->pageId('Page Uno', '1001');
        $this->psid(' page uno ', null, 'psid-a', 'Ana Cruz');
        $this->psid('Page Uno', null, 'psid-sheet', 'Ben Reyes', ['source' => 'sheet', 'status' => 'matched']);
        $ana = $this->order('Page Uno', 'Ana Cruz');
        $ben = $this->order('Page Uno', 'Ben Reyes');

        app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');
        $status = app(BotcakePsidMatcher::class)->recordSheetRow($this->sheetSetting(), 'Page Uno', 'Ben Reyes', 'psid-other', '2026-11-05');

        $this->assertSame('psid-a', $ana->fresh()->botcake_psid);
        $this->assertSame('conflict', $status, 'ang lumang sheet record na walang page id ay nakikita sa pangalan');
        $this->assertNull($ben->fresh()->botcake_psid);
    }
}
