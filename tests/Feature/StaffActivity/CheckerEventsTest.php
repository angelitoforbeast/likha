<?php

namespace Tests\Feature\StaffActivity;

use App\Http\Controllers\FromJntController;
use App\Http\Middleware\AllowedIpMiddleware;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga edit at validate sa Checker 1 at ang J&T status update: isang checker.* event kada order na talagang
 * nagbago, walang generic na route event, at walang customer data sa event.
 */
class CheckerEventsTest extends StaffActivityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // allowed_ip: hindi ito ang sinusubok dito (gaya ng OwnerPasswordChangeTest).
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('FULL NAME')->nullable();
            $t->string('PHONE NUMBER')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('PAGE')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->dateTime('ts_date')->nullable();
            $t->text('all_user_input')->nullable();
            $t->text('status_logs')->nullable();
            $t->text('HISTORICAL LOGS')->nullable();
            $t->boolean('edited_full_name')->default(false);
            $t->boolean('edited_item_name')->default(false);
            $t->boolean('edited_cod')->default(false);
            $t->integer('validate_1')->default(0);
            $t->integer('validate_2')->default(0);
            $t->integer('item_checker')->default(0);
            $t->timestamps();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->string('sender')->nullable();
            $t->string('cod')->nullable();
            $t->string('status')->nullable();
            $t->string('item_name')->nullable();
            $t->string('submission_time')->nullable();
            $t->string('receiver')->nullable();
            $t->string('receiver_cellphone')->nullable();
            $t->string('signingtime')->nullable();
            $t->string('remarks')->nullable();
            $t->text('status_logs')->nullable();
            $t->timestamps();
        });
    }

    private function order(array $attrs = []): int
    {
        return DB::table('macro_output')->insertGetId($attrs + [
            'FULL NAME' => 'Pangalan Ng Customer', 'PHONE NUMBER' => '09171234567', 'ITEM_NAME' => 'Gamot A',
            'COD' => '499', 'STATUS' => 'PENDING',
        ]);
    }

    /** [kind, source, ref] ng bawat event, ayon sa pagkakasunod. */
    private function recorded(): array
    {
        return $this->events()->map(fn ($e) => [$e->kind, $e->source, $e->ref])->all();
    }

    public function test_a_status_edit_writes_one_checker_status_event_and_no_route_event(): void
    {
        $user = $this->staff('Data Encoder');
        $id = $this->order();

        $this->actingAs($user)->postJson('/encoder/checker_1/update-field', ['id' => $id, 'field' => 'STATUS', 'value' => 'PROCEED'])
            ->assertOk();

        $e = $this->events()->sole();
        $this->assertSame(['checker.status', 'checker_1', (string) $id], [$e->kind, $e->source, $e->ref]);
        $this->assertSame([$user->id, 'Data Encoder', 'POST', 200], [(int) $e->user_id, $e->role, $e->method, (int) $e->status]);
        foreach (['Pangalan', '09171234567', 'PROCEED', '499'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($e));
        }
    }

    public function test_a_field_edit_writes_checker_edit_and_an_unchanged_value_writes_nothing(): void
    {
        $user = $this->staff();
        $id = $this->order();

        $this->actingAs($user)->postJson('/encoder/checker_1/update-field', ['id' => $id, 'field' => 'ITEM_NAME', 'value' => 'Gamot B']);
        $this->actingAs($user)->postJson('/encoder/checker_1/update-field', ['id' => $id, 'field' => 'ITEM_NAME', 'value' => 'Gamot B']);

        $this->assertSame([['checker.edit', 'checker_1', (string) $id]], $this->recorded());
    }

    public function test_bulk_save_of_three_rows_with_two_changed_writes_two_events(): void
    {
        $user = $this->staff();
        $a = $this->order();
        $b = $this->order();
        $c = $this->order();

        $this->actingAs($user)->post('/encoder/checker_1/update', ['records' => [
            $a => ['STATUS' => 'PROCEED', 'COD' => '499'],
            $b => ['STATUS' => 'PENDING', 'COD' => '599'],
            $c => ['STATUS' => 'PENDING', 'COD' => '499'],
        ]])->assertRedirect();

        $this->assertSame([
            ['checker.status', 'checker_1', (string) $a],
            ['checker.edit', 'checker_1', (string) $b],
        ], $this->recorded());
    }

    public function test_the_item_check_button_writes_checker_validate_only_for_orders_whose_flag_changed(): void
    {
        $user = $this->staff();
        $flips = $this->order();
        $already = $this->order(['item_checker' => 1]);
        $invalid = $this->order(['COD' => '']);

        $this->actingAs($user)->postJson('/macro_output/validate-items', ['ids' => [$flips, $already, $invalid]])->assertOk();

        $this->assertSame([['checker.validate', 'checker_1', (string) $flips]], $this->recorded());
    }

    public function test_a_jnt_status_update_writes_checker_status_per_changed_waybill(): void
    {
        $user = $this->staff();
        $moved = DB::table('from_jnts')->insertGetId(['waybill_number' => 'WB1', 'status' => 'In Transit']);
        DB::table('from_jnts')->insert(['waybill_number' => 'WB2', 'status' => 'In Transit']);

        $this->actingAs($user)->post('/jnt_update', ['jsonData' => json_encode([
            ['Waybill Number' => 'WB1', 'Status' => 'Delivered'],
            ['Waybill Number' => 'WB2', 'Status' => 'In Transit'],
            ['Waybill Number' => 'WB3', 'Status' => 'In Transit'],
        ])])->assertRedirect();

        $this->assertSame([['checker.status', 'jnt', (string) $moved]], $this->recorded());
    }

    public function test_a_writer_without_a_signed_in_user_writes_no_event(): void
    {
        DB::table('from_jnts')->insert(['waybill_number' => 'WB1', 'status' => 'In Transit']);
        $request = Request::create('/jnt_update', 'POST', ['jsonData' => json_encode([
            ['Waybill Number' => 'WB1', 'Status' => 'Delivered'],
        ])]);

        app(FromJntController::class)->updateOrInsert($request);

        $this->assertSame('Delivered', DB::table('from_jnts')->value('status'));
        $this->assertSame([], $this->recorded());
    }
}
