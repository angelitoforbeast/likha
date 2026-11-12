<?php

namespace Tests\Feature\Jnt;

use App\Jobs\BuildJntWaybillBulkPrint;
use App\Jobs\CreateJntOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec 267: J&T booking (CreateJntOrder) runs on `jnt-book` and bulk waybill printing (BuildJntWaybillBulkPrint) on
 * `jnt-print`, from every dispatch site. Several booking workers never call J&T twice for one shipment, a failing
 * order counts once in its run, and a missing SENDER_NAME fails at once. Sqlite in memory, a fake J&T; the retries
 * run through the real database queue worker.
 */
class JntQueuesTest extends TestCase
{
    private const PAGE = 'Likha Test Page';

    /** @var array<int, string> txlogisticid of each create call J&T received */
    private array $creates = [];

    /** fake J&T answer: 'ok' books with a mailno, 'down' throws a connection error */
    private string $jnt = 'ok';

    /** @var (callable(): void)|null runs inside the first create call (another worker taking the same shipment) */
    private $duringCall = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Artisan::call('migrate', ['--path' => [
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
            'database/migrations/2026_01_24_183708_create_jnt_batch_runs_table.php',
            'database/migrations/2026_02_10_184900_create_jnt_waybill_print_run_items_table.php',
            'database/migrations/2026_02_10_184900_create_jnt_waybill_print_runs_table.php',
            'database/migrations/2026_04_09_000001_create_jnt_accounts_table.php',
            'database/migrations/2026_04_09_000002_create_page_jnt_mappings_table.php',
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
        ]]);
        Schema::table('jnt_accounts', function (Blueprint $t) {
            $t->boolean('force_uppercase')->default(false);
            $t->boolean('use_item_sender_mapping')->default(false);
            $t->integer('pickup_days_offset')->default(0);
        });
        Schema::table('jnt_waybill_print_runs', function (Blueprint $t) {
            $t->unsignedInteger('pages')->default(0);
        });
        // macro_output and jnt_shipments come from legacy MySQL-only migrations: made here with the columns used.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            foreach (['PAGE', 'page_name', 'FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'ITEM_NAME', 'COD', 'STATUS'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['validate_1', 'validate_2', 'item_checker'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->dateTime('ts_date')->nullable();
            $t->timestamps();
        });
        Schema::create('jnt_shipments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('jnt_batch_run_id')->nullable();
            $t->unsignedBigInteger('macro_output_id')->nullable()->unique();
            $t->string('txlogisticid', 64)->nullable();
            $t->string('mailno', 64)->nullable();
            $t->boolean('success')->default(false);
            $t->string('reason')->nullable();
            $t->json('request_payload')->nullable();
            $t->json('request_protocol')->nullable();
            $t->json('response_payload')->nullable();
            foreach (['receiver_name', 'receiver_phone', 'receiver_prov', 'receiver_city', 'receiver_area', 'receiver_address'] as $c) {
                $t->string($c)->nullable();
            }
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('page_sender_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('SENDER_NAME')->nullable();
            $t->string('item_name')->nullable();
            $t->timestamps();
        });
        Schema::create('sender_addresses', function (Blueprint $t) {
            $t->id();
            foreach (['jnt_sender_phone', 'jnt_sender_prov', 'jnt_sender_city', 'jnt_sender_area', 'jnt_sender_address'] as $c) {
                $t->string($c)->nullable();
            }
            $t->timestamps();
        });

        config([
            'jnt.base_url' => 'https://jnt.test',
            'jnt.credentials' => ['eccompanyid' => 'EC-TEST', 'customerid' => 'CU-TEST', 'secret' => 'secret-test', 'api_key' => null],
        ]);
        $accountId = DB::table('jnt_accounts')->insertGetId(['label' => 'Test', 'eccompanyid' => 'EC-TEST', 'customerid' => 'CU-TEST']);
        DB::table('page_jnt_mappings')->insert(['page' => self::PAGE, 'jnt_account_id' => $accountId]);
        DB::table('sender_addresses')->insert(['jnt_sender_phone' => '09171234567', 'jnt_sender_prov' => 'METRO-MANILA',
            'jnt_sender_city' => 'TAGUIG', 'jnt_sender_area' => 'BAGUMBAYAN', 'jnt_sender_address' => '#20 1st Ave']);

        Http::fake(['jnt.test/*' => function (Request $req) {
            $tx = (string) (json_decode($req->data()['logistics_interface'] ?? '{}', true)['txlogisticid'] ?? '');
            $this->creates[] = $tx;
            if ($this->duringCall) {
                $during = $this->duringCall;
                $this->duringCall = null;
                $during();
            }
            if ($this->jnt === 'down') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['responseitems' => [['success' => 'true', 'txlogisticid' => $tx, 'mailno' => 'JT'.substr($tx, -8)]]]);
        }]);
    }

    private function macroRow(array $over = []): int
    {
        return DB::table('macro_output')->insertGetId(array_merge([
            'PAGE' => self::PAGE, 'page_name' => self::PAGE, 'FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '09181234567',
            'ADDRESS' => '12 Rizal St', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'PASIG', 'BARANGAY' => 'KAPITOLYO',
            'ITEM_NAME' => '1 x Soap', 'COD' => '499', 'STATUS' => 'PROCEED', 'validate_1' => 1, 'validate_2' => 1,
            'item_checker' => 1, 'ts_date' => '2026-11-12 10:00:00', 'created_at' => '2026-11-12 10:00:00',
        ], $over));
    }

    private function senderMapping(): void
    {
        DB::table('page_sender_mappings')->insert(['PAGE' => self::PAGE, 'SENDER_NAME' => 'LIKHA SENDER', 'item_name' => null]);
    }

    /** A run of $n shipments as createBatch writes it; returns the shipment ids. */
    private function bookingRun(int $n, array $runOver = []): array
    {
        $runId = DB::table('jnt_batch_runs')->insertGetId(array_merge([
            'filters' => json_encode(['date' => '2026-11-12', 'page' => self::PAGE]), 'total' => $n, 'processed' => 0,
            'success_count' => 0, 'fail_count' => 0, 'status' => 'running', 'started_at' => now(),
        ], $runOver));
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = DB::table('jnt_shipments')->insertGetId(['jnt_batch_run_id' => $runId, 'macro_output_id' => $this->macroRow(), 'success' => 0]);
        }

        return $ids;
    }

    private function batchRun(): object
    {
        return DB::table('jnt_batch_runs')->first();
    }

    /**
     * The live worker: queue:work database --queue=jnt-book until the queue is empty (retries included). --memory is
     * high because the whole suite runs in one process: past the default 128 MB the worker stops after one job.
     */
    private function work(string $queue = 'jnt-book'): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--sleep' => 0,
            '--memory' => 4096]);
    }

    private function pushBooking(int $shipmentId): void
    {
        CreateJntOrder::dispatch($shipmentId)->onConnection('database'); // the queue is the job's own, as in the controllers
    }

    // ---- 1. every dispatch site lands on the J&T queues ----

    public function test_create_batch_queues_every_booking_on_jnt_book(): void
    {
        Queue::fake();
        $this->macroRow();
        $this->macroRow();

        $this->post('/jnt/orders/batch', ['date' => '2026-11-12', 'page' => self::PAGE])->assertRedirect();

        $this->assertJobsOn(CreateJntOrder::class, 'jnt-book', 2);
    }

    public function test_retry_failed_and_retry_by_date_page_queue_on_jnt_book(): void
    {
        Queue::fake();
        $ids = $this->bookingRun(2, ['processed' => 2, 'fail_count' => 2, 'status' => 'finished']);
        $runId = $this->batchRun()->id;

        $this->post("/jnt/orders/batch/{$runId}/retry-failed")->assertRedirect();
        $this->assertJobsOn(CreateJntOrder::class, 'jnt-book', 2);

        $this->post('/jnt/orders/retry-by-date-page', ['date' => '2026-11-12', 'page' => self::PAGE])->assertRedirect();
        $this->assertJobsOn(CreateJntOrder::class, 'jnt-book', 4);
        $this->assertCount(2, $ids);
    }

    public function test_the_jnt_shipments_routes_queue_on_jnt_book(): void
    {
        Queue::fake();
        $macroId = $this->macroRow();

        $this->post("/jnt/shipments/create/{$macroId}")->assertRedirect();
        $this->post('/jnt/shipments/bulk-create', ['date' => '2026-11-12', 'page' => self::PAGE])->assertRedirect();

        $this->assertJobsOn(CreateJntOrder::class, 'jnt-book', 2);
    }

    public function test_bulk_print_queues_on_jnt_print(): void
    {
        Queue::fake();

        $this->post('/jnt/waybills/print-bulk', ['mode' => 'selected', 'date' => '2026-11-12', 'mailnos' => ['JT0001', 'JT0002']])
            ->assertRedirect();
        $this->post('/jnt/waybills/print-bulk', ['mode' => 'all', 'date' => '2026-11-12', 'filter_by' => 'page', 'filter_value' => self::PAGE])
            ->assertRedirect();

        $this->assertJobsOn(BuildJntWaybillBulkPrint::class, 'jnt-print', 2);
    }

    public function test_the_queue_names_come_from_config_and_survive_a_retry_from_failed_jobs(): void
    {
        config(['jnt.queues.book' => 'jnt-book-x', 'jnt.queues.print' => 'jnt-print-x']);
        $this->assertSame('jnt-book-x', (new CreateJntOrder(1))->queue);
        $this->assertSame('jnt-print-x', (new BuildJntWaybillBulkPrint(1))->queue);

        // A retried job (queue:retry, a release after an exception) is the unserialized job: its queue stays.
        config(['jnt.queues.book' => 'jnt-book']);
        $job = unserialize(serialize(new CreateJntOrder(7)));
        $this->assertSame('jnt-book', $job->queue);
    }

    public function test_the_print_timeout_is_below_the_database_retry_after(): void
    {
        $job = new BuildJntWaybillBulkPrint(1);
        $this->assertSame(1800, $job->timeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), CreateJntOrder::LOCK_SECONDS);
        $this->assertGreaterThan((new CreateJntOrder(1))->timeout, CreateJntOrder::LOCK_SECONDS);
    }

    // ---- 2. two workers never book one shipment twice ----

    public function test_a_second_worker_on_the_same_shipment_while_the_first_waits_for_jnt_does_not_call_jnt(): void
    {
        $this->senderMapping();
        [$id] = $this->bookingRun(1);
        // Worker B takes the duplicate job while worker A is inside its J&T call.
        $this->duringCall = fn () => (new CreateJntOrder($id))->handle();

        (new CreateJntOrder($id))->handle();

        $this->assertCount(1, $this->creates);
        $shipment = DB::table('jnt_shipments')->find($id);
        $this->assertNotEmpty($shipment->mailno);
        $run = $this->batchRun();
        $this->assertSame([1, 1, 0, 'finished'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
    }

    public function test_a_shipment_locked_by_another_worker_is_left_to_it(): void
    {
        $this->senderMapping();
        [$id] = $this->bookingRun(1);
        $held = Cache::lock('jnt-book:shipment:'.$id, CreateJntOrder::LOCK_SECONDS);
        $this->assertTrue($held->get());

        (new CreateJntOrder($id))->handle();

        $this->assertCount(0, $this->creates);
        $this->assertSame(0, (int) $this->batchRun()->processed);
        $held->release();
    }

    public function test_a_duplicated_job_on_the_queue_books_once(): void
    {
        $this->senderMapping();
        [$id] = $this->bookingRun(1);
        $this->pushBooking($id);
        $this->pushBooking($id);

        $this->work();

        $this->assertCount(1, $this->creates);
        $run = $this->batchRun();
        $this->assertSame([1, 1, 0, 'finished'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ---- 3. a failure counts once ----

    public function test_a_failing_order_is_tried_three_times_and_counts_once(): void
    {
        $this->senderMapping();
        [$bad, $good] = $this->bookingRun(2);
        $this->jnt = 'down';
        $this->pushBooking($bad);

        $this->work();

        $this->assertCount(3, $this->creates, 'J&T is tried 3 times ($tries)');
        $run = $this->batchRun();
        $this->assertSame([1, 0, 1, 'running'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString('timed out', (string) DB::table('jnt_shipments')->find($bad)->reason);

        // The other order books: the run reaches finished with 1 ok and 1 failed.
        $this->jnt = 'ok';
        $this->pushBooking($good);
        $this->work();
        $run = $this->batchRun();
        $this->assertSame([2, 1, 1, 'finished'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_missing_sender_name_fails_once_without_retry(): void
    {
        [$id] = $this->bookingRun(1); // no page_sender_mappings row
        $this->pushBooking($id);
        $tries = 0;
        app('events')->listen(JobProcessing::class, function () use (&$tries) {
            $tries++;
        });

        $this->work();

        $this->assertCount(0, $this->creates, 'J&T is never called');
        $run = $this->batchRun();
        $this->assertSame([1, 0, 1, 'finished'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('jnt-book', $failed[0]->queue);
        $this->assertSame(1, $tries, 'failed on the first try, no retry');
        $this->assertStringStartsWith('SENDER_NAME_NOT_FOUND', (string) DB::table('jnt_shipments')->find($id)->reason);
    }

    public function test_retry_failed_after_a_counted_failure_books_and_finishes_the_run(): void
    {
        [$id] = $this->bookingRun(1);
        $this->pushBooking($id);
        $this->work(); // no sender: failed, counted once
        $this->senderMapping();
        Cache::flush();

        $runId = $this->batchRun()->id;
        config(['queue.default' => 'database']);
        $this->post("/jnt/orders/batch/{$runId}/retry-failed")->assertRedirect();
        $this->work();

        $this->assertCount(1, $this->creates);
        $run = $this->batchRun();
        $this->assertSame([1, 1, 0, 'finished'], [(int) $run->processed, (int) $run->success_count, (int) $run->fail_count, $run->status]);
    }

    private function assertJobsOn(string $class, string $queue, int $count): void
    {
        $jobs = Queue::pushed($class);
        $this->assertCount($count, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame($queue, $job->queue);
        }
    }
}
