<?php

namespace Tests\Feature\EncoderTimetable;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 265 tests (sqlite in memory). staff_events, jnt_batch_runs and the nav tables come from their real
 * migrations; macro_output (legacy MySQL-only migrations), jnt_waybill_print_runs (no create migration in the repo) and
 * downloaded_macro_output_logs are made by hand with the columns the page reads. macro_output gets the ts_date index the
 * live table has, so the query plan is tested against the same indexes.
 */
abstract class EncoderTimetableTestCase extends TestCase
{
    protected const NAV_MIGRATION = 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php';

    protected const LINK_MIGRATION = 'database/migrations/2026_11_24_100000_add_encoding_timetable_nav_link.php';

    protected const URL = '/encoder/timetable';

    private int $nextOrderId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Carbon::setTestNow('2026-11-12 12:00:00');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) { // counted by the layout's view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('TIMESTAMP', 100)->nullable();
            $t->string('PAGE')->nullable();
            $t->string('STATUS')->nullable();
            $t->date('ts_date')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('jnt_waybill_print_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->date('date')->nullable();
            $t->string('mode', 20)->nullable();
            $t->string('filter_by', 20)->nullable();
            $t->string('filter_value')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('ok_count')->default(0);
            $t->unsignedInteger('fail_count')->default(0);
            $t->unsignedInteger('pages')->default(0);
            $t->string('status', 20)->default('queued');
            $t->text('message')->nullable();
            $t->string('output_type')->nullable();
            $t->string('output_path')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('downloaded_macro_output_logs', function (Blueprint $t) {
            $t->id();
            $t->string('timestamp', 50);
            $t->string('page')->nullable();
            $t->string('downloaded_by')->nullable();
            $t->timestamp('downloaded_at')->nullable();
            $t->timestamps();
        });

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_01_24_183708_create_jnt_batch_runs_table.php',
            'database/migrations/2026_10_16_100002_create_staff_events_table.php',
            'database/migrations/2026_10_16_200001_add_source_to_staff_events.php',
            self::NAV_MIGRATION,
            self::LINK_MIGRATION,
        ], '--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(?string $role, string $name = 'Test User'): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('u') . '@example.test', 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    /** One order of $orderDate on $page; returns its macro_output id. */
    protected function order(string $orderDate, string $page): int
    {
        $id = $this->nextOrderId++;
        DB::table('macro_output')->insert(['id' => $id, 'ts_date' => $orderDate, 'PAGE' => $page, 'STATUS' => 'PROCEED']);

        return $id;
    }

    /** A Checker 1 action on an order, as StaffEvents::recordChecker writes it. */
    protected function checker(int $orderId, string $at, int $userId = 1, string $kind = 'checker.validate', string $source = 'checker_1'): void
    {
        DB::table('staff_events')->insert([
            'user_id' => $userId, 'role' => 'Data Encoder', 'kind' => $kind, 'source' => $source,
            'method' => 'POST', 'status' => 200, 'ref' => (string) $orderId, 'created_at' => $at,
        ]);
    }

    /** A J&T booking run as JntOrderUiController::createBatch and CreateJntOrder write it. */
    protected function jntRun(string $orderDate, string $page, string $status, ?string $finishedAt, ?string $createdAt = null): void
    {
        $createdAt ??= $finishedAt ?? Carbon::parse($orderDate)->addDay()->setTime(9, 0)->toDateTimeString();
        DB::table('jnt_batch_runs')->insert([
            'filters' => json_encode(['date' => $orderDate, 'page' => $page]),
            'total' => 10, 'processed' => 10, 'success_count' => 10, 'fail_count' => 0,
            'status' => $status, 'started_at' => $createdAt, 'finished_at' => $finishedAt, 'created_by' => 1,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /** A bulk waybill print run as JntWaybillPrintController::printBulk and BuildJntWaybillBulkPrint write it. */
    protected function printRun(string $orderDate, string $filterValue, string $status, ?string $finishedAt, string $filterBy = 'item'): void
    {
        $createdAt = $finishedAt ?? Carbon::parse($orderDate)->addDay()->setTime(10, 0)->toDateTimeString();
        DB::table('jnt_waybill_print_runs')->insert([
            'created_by' => 1, 'date' => $orderDate, 'mode' => 'all', 'filter_by' => $filterBy, 'filter_value' => $filterValue,
            'status' => $status, 'started_at' => $createdAt, 'finished_at' => $finishedAt,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /** @return array<string, array> the page's rows keyed by order date */
    protected function rowsByDate($response): array
    {
        return collect($response->viewData('rows'))->keyBy('date')->all();
    }
}
