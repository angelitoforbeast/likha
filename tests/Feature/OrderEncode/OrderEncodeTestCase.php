<?php

namespace Tests\Feature\OrderEncode;

use App\Services\Chatbot\ChatbotSheet;
use App\Services\OrderEncode\LocationStore;
use App\Services\OrderEncode\Sheets;
use App\Services\PageSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Base ng feature tests ng order encode: ang chatbot tables, order_encodes, ang mga column ng macro_output na kailangan ng live,
 * at ang fake na Google sheet (walang tawag sa Google kailanman).
 */
abstract class OrderEncodeTestCase extends ChatblastTestCase
{
    protected const AFTER = '1AfterMacroSheetIdAbCdEfGhIjKlMnOp';
    protected const MACRO = '1MacroSheetIdAbCdEfGhIjKlMnOpQrSt';
    protected const ORDERS = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';
    protected const PAGE = 'Page Uno';

    protected FakeSheets $google;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_11_04_100000_create_order_encodes_table.php',
        ]]);
        Schema::table('macro_output', function (Blueprint $t) {
            foreach (['TIMESTAMP', 'FULL NAME', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'AI ANALYZE', 'APP SCRIPT CHECKER', 'STATUS'] as $c) $t->string($c)->nullable();
            foreach (['all_user_input', 'shop_details', 'SHOP DETAILS', 'extracted_details', 'CXD'] as $c) $t->text($c)->nullable();
        });
        config(['services.openai.order_encode_key' => Fixtures::KEY]);

        $this->google = new FakeSheets();
        $this->app->instance(Sheets::class, $this->google);
        $this->app->instance(ChatbotSheet::class, $this->google);

        // Ang LocationData ay file sa storage/app/order-encode; sa test, nasa sariling folder.
        $this->store = new FakeLocationStore();
        $this->app->instance(LocationStore::class, $this->store);
    }

    protected FakeLocationStore $store;

    protected function tearDown(): void
    {
        $this->store->cleanup();
        parent::tearDown();
    }

    /** Ang mga setting ng page para sa order encode, at ang mga listahan ng lugar. */
    protected function configurePage(string $mode, string $page = self::PAGE, bool $lists = true): void
    {
        $s = app(PageSettings::class);
        $s->set($page, 'order_encode', 'mode', $mode);
        $s->set($page, 'order_encode', 'compare_sheet', 'https://docs.google.com/spreadsheets/d/' . self::AFTER . '/edit');
        $s->set($page, 'order_encode', 'macro_sheet', self::MACRO);
        if ($lists) {
            $this->store->write(LocationStore::MACRO, self::MACRO, Fixtures::macroRows());
            $this->store->write(LocationStore::AFTER, self::AFTER, Fixtures::checkerRows());
        }
    }

    /** Ang row ng sheet step (All Orders A..H), gaya ng isinusulat ng flow, may apostrophe sa unahan. */
    protected function orderRow(array $over = []): array
    {
        return $over + ['10:15 23-10-2026', 'Page Uno', 'Ana Cruz', '09171234567', 'Hi po, order ko 2 pcs',
            "ITEM: GLOW SERUM\nQUANTITY: 2\nPRICE: ₱998",
            "- Name: Ana Cruz\n- Phone Number: 09171234567\n- Province: Cebu\n- City: Cebu City\n- Brgy: Lahug\n- Address: Blk 5 Lot 3 Lahug", '998'];
    }
}
