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

        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD')->nullable();
        });
        Schema::table('from_jnts', function (Blueprint $t) {
            $t->string('receiver')->nullable();
        });
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_10_18_300000_create_botcake_messages_table.php',
        ]]);
    }

    /** Isang page na kumpleto ang Botcake settings para sa chatblast. */
    protected function page(string $page, string $id = '1001', array $except = []): void
    {
        $s = app(PageSettings::class);
        foreach (['page_id' => $id, 'token' => self::TOKEN, 'flow.shipping' => '5501', 'field.message' => '7701'] as $key => $value) {
            if (!in_array($key, $except, true)) $s->set($page, 'botcake', $key, $value);
        }
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

        return $order;
    }
}
