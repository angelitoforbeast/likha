<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** Base ng mga test ng final compare: ang edited_* ng macro_output at ang kolum ng final compare. */
abstract class FinalTestCase extends OrderEncodeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('macro_output', function (Blueprint $t) {
            foreach (['edited_full_name', 'edited_address', 'edited_province', 'edited_city', 'edited_barangay', 'edited_cod', 'edited_item_name'] as $c) $t->boolean($c)->default(false);
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_05_100000_add_final_compare_to_order_encodes_table.php']]);
    }

    /** Ang final na order sa macro_output (galing sa Macro import at staff). */
    protected function macro(array $over = []): MacroOutput
    {
        return MacroOutput::create($over + [
            'TIMESTAMP' => '10:15 23-10-2026', 'PAGE' => self::PAGE, 'fb_name' => 'Ana Cruz', 'PROVINCE' => 'CEBU', 'CITY' => 'CEBU-CITY', 'BARANGAY' => 'LAHUG',
            'ADDRESS' => 'Blk 5, Lot 3  Lahug', 'ITEM_NAME' => '2 x GLOW SERUM', 'COD' => '998', 'STATUS' => 'PROCEED', 'APP SCRIPT CHECKER' => '✅',
        ]);
    }

    /** Ang tapos na encode ng Likha para sa order na iyon. */
    protected function encoded(?MacroOutput $m, array $outputs = [], array $more = []): OrderEncode
    {
        return OrderEncode::create($more + [
            'page' => self::PAGE, 'psid' => null, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => random_int(1000, 9_999_999), 'mode' => 'import', 'status' => 'done',
            'input' => ['row' => [], 'source' => 'import'], 'macro_output_id' => $m?->id,
            'outputs' => $outputs + ['province' => 'CEBU', 'city' => 'CEBU-CITY', 'barangay' => 'LAHUG', 'address_line' => 'Blk 5 Lot 3 Lahug', 'item' => '2 x GLOW SERUM', 'cod' => '998', 'final' => '✅', 'proceed' => true],
            'steps' => [['step' => 'address_line1', 'status' => 'WROTE_ADDRESS_LINE1', 'model' => 'gpt-4o-mini', 'tokens_in' => 100, 'tokens_out' => 10, 'ms' => 420]],
            'usage' => ['gpt-4o-mini' => ['requests' => 1, 'tokens_in' => 100, 'tokens_out' => 10]],
        ]);
    }
}
