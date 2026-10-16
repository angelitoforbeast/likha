<?php

namespace Tests\Feature\SummaryOverall;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\MacroOutputTestCase;

/**
 * Base ng /summary/overall tests (sqlite in-memory).
 * Fixture: delivered, returned, for return, in transit, hold, cannot proceed, ODZ,
 * dalawang page, dalawang item, dalawang araw, COD na may comma.
 */
abstract class SummaryOverallTestCase extends MacroOutputTestCase
{
    protected const START = '2026-10-01';
    protected const END   = '2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();

        // from_jnts: gawa sa kamay, mga column lang na binabasa ng page.
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->dateTime('submission_time')->nullable();
            $t->string('waybill_number')->nullable();
            $t->string('status')->nullable();
            $t->decimal('total_shipping_cost', 12, 2)->nullable();
            $t->timestamps();
        });

        // tasks: binibilang ng view composer sa bawat page
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
            $t->date('due_date')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        foreach ([['cod_fee_rate', 0.015], ['cod_fee_vat_rate', 0.12]] as [$key, $value]) {
            DB::table('fee_settings')->insert([
                'setting_key' => $key, 'setting_value' => $value, 'effective_date' => '2025-01-01',
                'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Ang buong fixture ng page. */
    protected function seedFixture(): void
    {
        $mo = function (string $day, string $page, string $item, string $status, string $cod, ?string $wb) {
            DB::table('macro_output')->insert([
                'PAGE' => $page, 'ITEM_NAME' => $item, 'STATUS' => $status, 'COD' => $cod,
                'waybill' => $wb, 'ts_date' => $day, 'TIMESTAMP' => '10:00 ' . date('d-m-Y', strtotime($day)),
                'created_at' => $day . ' 10:00:00', 'updated_at' => $day . ' 10:00:00',
            ]);
        };
        // Page Alpha, Oct 1
        $mo('2026-10-01', 'Page Alpha', 'Item One', 'Proceed',        '1,250.00', 'WB01'); // delivered
        $mo('2026-10-01', 'Page Alpha', 'Item One', 'Proceed',        '1,250.00', 'WB02'); // returned
        $mo('2026-10-01', 'Page Alpha', 'Item Two', 'PROCEED',        '899',      'WB03'); // for return
        $mo('2026-10-01', 'Page Alpha', 'Item Two', 'proceed',        '899',      'WB04'); // in transit
        $mo('2026-10-01', 'Page Alpha', 'Item One', 'Proceed',        '1,250',    null);   // hold
        $mo('2026-10-01', 'Page Alpha', 'Item Two', 'Proceed',        '899',      '');     // hold, empty waybill
        $mo('2026-10-01', 'Page Alpha', 'Item One', 'Cannot Proceed', '1,250',    null);
        $mo('2026-10-01', 'Page Alpha', 'Item Two', 'ODZ',            '899',      null);
        // Page Alpha, Oct 2
        $mo('2026-10-02', 'Page Alpha', 'Item One', 'Proceed',        '1250',     'WB05'); // in transit then delivered
        $mo('2026-10-02', 'Page Alpha', 'Item Two', 'Proceed',        '899',      'WB06'); // DELIVERED
        $mo('2026-10-02', 'Page Alpha', 'Item Two', 'Proceed',        '899',      'WB06'); // duplicate row
        // Page Beta (with a padded label), Oct 1 and 2
        $mo('2026-10-01', ' page beta', 'Item One', 'Proceed',        '1,250.00', 'WB07');
        $mo('2026-10-01', 'Page Beta',  'Item One', 'Proceed',        '1,250.00', 'WB08');
        $mo('2026-10-01', 'Page Beta',  'Item One', 'Proceed',        '1,250.00', null);   // hold
        $mo('2026-10-02', 'Page Beta',  'Item-One', 'Proceed',        '1,300.00', 'WB09'); // picked up
        // Outside the range
        $mo('2026-09-20', 'Page Alpha', 'Item One', 'Proceed',        '1,250.00', 'WB10');

        $jnt = function (string $wb, string $status, ?string $submitted, ?float $ship) {
            DB::table('from_jnts')->insert([
                'waybill_number' => $wb, 'status' => $status, 'submission_time' => $submitted,
                'total_shipping_cost' => $ship, 'created_at' => '2026-10-05 08:00:00', 'updated_at' => '2026-10-05 08:00:00',
            ]);
        };
        $jnt('WB01', 'Delivered',              '2026-10-03 09:00:00', 90);
        $jnt('WB02', 'Returned to sender',     '2026-10-02 09:00:00', 95);
        $jnt('WB03', 'For Return',             '2026-10-04 09:00:00', 88);
        $jnt('WB04', 'In Transit',             '2026-10-02 12:00:00', 85);
        $jnt('WB05', 'In Transit',             '2026-10-03 09:00:00', 80);
        $jnt('WB05', 'DELIVERED',              '2026-10-04 09:00:00', 80);
        $jnt('WB06', 'DELIVERED',              null,                  null);
        $jnt('WB07', 'Delivered',              '2026-10-02 09:00:00', 92);
        $jnt('WB08', 'delivered',              '2026-10-03 09:00:00', 92);
        $jnt('WB09', 'Picked Up',              '2026-10-03 09:00:00', 70);
        $jnt('WB10', 'Delivered',              '2026-09-21 09:00:00', 90);

        $ads = function (string $day, string $page, string $spent) {
            DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'amount_spent_php' => $spent]);
        };
        $ads('2026-10-01', 'Page Alpha',  '500.00');
        $ads('2026-10-01', ' Page Alpha', '100');
        $ads('2026-10-02', 'Page Alpha',  '300.50');
        $ads('2026-10-01', 'Page Beta',   '400');
        $ads('2026-10-02', 'Page Beta',   '200');
        $ads('2026-10-02', 'Page Gamma',  '150');
        $ads('2026-09-20', 'Page Alpha',  '999');

        foreach ([['Item One', '2026-09-01', 400], ['Item One', '2026-10-02', 420], ['Item Two', '2026-09-01', 300]] as [$item, $date, $cost]) {
            DB::table('cogs_ceo')->insert(['item_name' => $item, 'date' => $date, 'unit_cost' => $cost]);
            DB::table('cogs')->insert(['item_name' => $item, 'date' => $date, 'unit_cost' => $cost - 50]);
        }
    }

    /** GET /summary/overall/data bilang CEO; ibinabalik ang JSON at ang bilang ng statements. */
    protected function fetchData(array $query, string $role = 'CEO'): array
    {
        $email = strtolower(preg_replace('/\W+/', '', $role)) . '@example.test';
        $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email));
        $count = 0;
        DB::listen(function () use (&$count) { $count++; });
        $res = $this->getJson('/summary/overall/data?' . http_build_query($query));
        $res->assertOk();

        return [$res->json(), $count];
    }
}
