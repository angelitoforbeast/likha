<?php

namespace Tests\Feature\Chatblast;

use App\Models\MacroOutput;
use App\Services\PageSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Base ng /chatblast tests: ang Botcake base (sqlite, fake HTTP at orasan) at ang mga column na kailangan ng mensahe
 * (ITEM_NAME, COD, receiver), ang app_settings at ang botcake_messages.
 */
abstract class ChatblastTestCase extends BotcakeApiTestCase
{
    protected const TOKEN = 'tok-chatblast-test-9w2e';

    protected function setUp(): void
    {
        parent::setUp();
        // Spec 273: the gateway watcher's probe of our own network before a switch answers here (no real call); a test
        // that wants it failing points server_probe_url elsewhere and fakes that (the first matching fake wins).
        \Illuminate\Support\Facades\Http::fake([(string) config('services.gateway.auto_switch.server_probe_url') => \Illuminate\Support\Facades\Http::response('', 200)]);
        // The connect-phase retry waits 2 s in production; the tests do not wait (the spec 273 tests check the wait).
        // The older switch tests send their failures in the same second: they test other rules (burst hold, moves between
        // gateways), so they keep counting every failure as a strike and take no live look before a no_answer switch.
        // GatewaySwitchSteadierTest tests the spec 273 window and the live look.
        config(['services.gateway.auto_switch.connect_retry_ms' => 0, 'services.gateway.auto_switch.burst_window_seconds' => 0,
            'services.gateway.auto_switch.no_answer_min_seconds' => 0, 'services.gateway.auto_switch.recheck_max_holds' => 0]);

        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD')->nullable();
        });
        Schema::table('from_jnts', function (Blueprint $t) {
            $t->string('receiver')->nullable();
            $t->string('rider_name')->nullable(); // gaya ng production (2026_09_16_110000)
            $t->string('rider_phone', 30)->nullable();
        });
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_10_18_300000_create_botcake_messages_table.php',
            'database/migrations/2026_09_16_100000_create_jnt_track_sync_runs_table.php',
            'database/migrations/2026_10_21_100000_create_jnt_latest_scans_table.php',
        ]]);
        if ($this->withCampaigns) $this->migrateCampaigns();
    }

    /**
     * Ang build at ang flow panel ay naglalagay ng set_time_limit(55) para sa web request; sa test, iisang proseso ang
     * buong suite (at sa Windows ay oras ng orasan ito), kaya ibinabalik sa walang hangganan pagkatapos ng bawat test.
     */
    protected function tearDown(): void
    {
        @set_time_limit(0);
        // Ang "handa na ang tags" ay naka-cache kada app object id; ang susunod na test na may kaparehong id ay hindi dapat magmana nito.
        (new \ReflectionProperty(\App\Services\Chatbot\ChatbotTags::class, 'ready'))->setValue(null, []);
        parent::tearDown();
    }

    /** false: ang test ang magpapatakbo ng migration ng campaigns (hal. para may laman muna bago ito). */
    protected bool $withCampaigns = true;

    protected function migrateCampaigns(): void
    {
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_18_400000_create_chatblast_campaigns_table.php',
            'database/migrations/2026_10_19_100000_add_schedule_to_chatblast_campaigns.php',
            'database/migrations/2026_10_19_100100_create_chatblast_messages_table.php',
            'database/migrations/2026_10_19_100200_add_send_range_to_chatblast_campaigns.php',
            'database/migrations/2026_10_19_100300_add_auto_runs_to_chatblast_campaigns.php',
        ]]);
    }

    /**
     * Spec 271: pekeng Botcake na pumapasa sa shipping status setup check ng "+ Page" (ang flow, ang custom field at ang
     * APPROVED na template na may {{1}}). Ilagay bago ang pangkalahatang 'botcake.io/*' (ang unang tugma ang sagot).
     */
    protected static function setupOkFakes(): array
    {
        return [
            'botcake.io/*/custom_fields*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => [['id' => 801, 'name' => 'message']]]),
            'botcake.io/*/flows/*/statistics*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['sent' => 0, 'delivery' => 0]]),
            'botcake.io/*/flows*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['flows' => [
                ['id' => 502, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false]], 'folders' => []]]),
            'botcake.io/*/message_templates*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => [
                ['name' => 'post_order', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => "Update po\n{{1}}"]]]]]),
        ];
    }

    /**
     * Isang page na kumpleto ang Botcake settings para sa chatblast. $tested: may matagumpay na test send pagkatapos ng
     * settings (botcake.test_ok), kaya Ready ang page; false para sa page na kailangan pa ng test send.
     */
    protected function page(string $page, string $id = '1001', array $except = [], bool $tested = true): void
    {
        $s = app(PageSettings::class);
        foreach (['page_id' => $id, 'token' => self::TOKEN, 'flow.shipping' => '5501', 'field.message' => '7701'] as $key => $value) {
            if (!in_array($key, $except, true)) $s->set($page, 'botcake', $key, $value);
        }
        if ($tested) $s->set($page, 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
    }

    /** Isang order na may J&T status ng waybill nito. */
    protected function shipped(string $page, string $name, string $waybill, ?string $psid, string $jnt = 'Delivering', int $daysAgo = 0, array $extra = []): MacroOutput
    {
        $receiver = $extra['receiver'] ?? 'Receiver ' . $name;
        unset($extra['receiver']);
        $order = MacroOutput::create(array_merge([
            'PAGE' => $page, 'fb_name' => $name, 'waybill' => $waybill, 'botcake_psid' => $psid,
            'ts_date' => now('Asia/Manila')->subDays($daysAgo)->toDateString(), 'ITEM_NAME' => 'Item A', 'COD' => '499',
        ], $extra));
        DB::table('from_jnts')->insert(['waybill_number' => $waybill, 'status' => $jnt, 'receiver' => $receiver]);
        // Delivering: ang kusang track-sync ay may On Delivery scan ngayong araw, nasuri ngayon (ang kailangan ng chatblast).
        if ($jnt === 'Delivering') $this->scan($waybill);

        return $order;
    }

    /** Ang pinakabagong scan ng waybill na isinulat ng track-sync (jnt_latest_scans). */
    protected function scan(string $waybill, string $type = 'On Delivery', ?string $time = null, ?string $checkedAt = null, ?string $rider = null, ?string $phone = null): void
    {
        DB::table('jnt_latest_scans')->updateOrInsert(['waybill' => $waybill], [
            'scan_type' => $type, 'scan_time' => $time ?? now('Asia/Manila')->format('Y-m-d H:i:s'),
            'rider_name' => $rider, 'rider_phone' => $phone, 'checked_at' => $checkedAt ?? now()->format('Y-m-d H:i:s'),
        ]);
    }
}
