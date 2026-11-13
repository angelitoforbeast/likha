<?php

namespace Tests\Feature\Jnt;

use App\Http\Controllers\JntOrderUiController;
use App\Jobs\BuildJntWaybillBulkPrint;
use App\Jobs\CreateJntOrder;
use App\Jobs\FetchJntLabel;
use App\Services\Jnt\JntLabelFetcher;
use App\Services\Jnt\JntLabelLimiter;
use App\Services\Jnt\JntLabelStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Spec 269: the label of a booked waybill is fetched right after booking and kept; a print run stitches kept labels
 * and fetches only the missing ones, 10 at a time under a shared limit; Create Batch can take all ready pages.
 * Sqlite in memory, a fake J&T (never the real one), a fake local disk.
 */
class JntLabelsAheadTest extends TestCase
{
    private const PAGE = 'Likha Test Page';

    private const DATE = '2026-11-12';

    /** @var array<int, string> billCode of each label call J&T received */
    private array $labelCalls = [];

    /** @var array<int, string> eccompanyid of each label call */
    private array $labelAccounts = [];

    /** @var array<int, string> txlogisticid of each booking call */
    private array $creates = [];

    /** label answers: 'pdf' a label, 'none' no label yet, 'other' the label of another waybill */
    private string $label = 'pdf';

    /** booking answers: 'ok' a mailno, 'refused' J&T says no */
    private string $create = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Storage::fake('local');

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
            'jnt.credentials' => ['eccompanyid' => 'EC-ENV', 'customerid' => 'CU-ENV', 'secret' => 'secret-test', 'api_key' => null],
        ]);
        $this->mapPage(self::PAGE, 'EC-PAGE');
        DB::table('page_sender_mappings')->insert(['PAGE' => self::PAGE, 'SENDER_NAME' => 'LIKHA SENDER', 'item_name' => null]);
        DB::table('sender_addresses')->insert(['jnt_sender_phone' => '09171234567', 'jnt_sender_prov' => 'METRO-MANILA',
            'jnt_sender_city' => 'TAGUIG', 'jnt_sender_area' => 'BAGUMBAYAN', 'jnt_sender_address' => '#20 1st Ave']);

        Http::fake(['jnt.test/*' => function (Request $req) {
            $data = $req->data();
            $li = json_decode($data['logistics_interface'] ?? '{}', true) ?: [];
            if (($data['msg_type'] ?? '') === 'BILLQUERY') {
                $this->labelCalls[] = (string) $li['billCode'];
                $this->labelAccounts[] = (string) $data['eccompanyid'];
                if ($this->label === 'none') {
                    return Http::response(['responseitems' => [['success' => 'false', 'reason' => 'not ready']]]);
                }
                if ($this->label === 'other') {
                    return Http::response(['responseitems' => [['billCode' => '730000000777', 'base64Url' => base64_encode($this->pdf('730000000777'))]]]);
                }

                return Http::response(['responseitems' => [['base64Url' => base64_encode($this->pdf((string) $li['billCode']))]]]);
            }
            $tx = (string) ($li['txlogisticid'] ?? '');
            $this->creates[] = $tx;
            if ($this->create === 'refused') {
                return Http::response(['responseitems' => [['success' => 'false', 'txlogisticid' => $tx, 'reason' => 'B063']]]);
            }

            return Http::response(['responseitems' => [['success' => 'true', 'txlogisticid' => $tx, 'mailno' => '7'.substr($tx, -8)]]]);
        }]);
    }

    // ---- helpers ----

    private function mapPage(string $page, string $ec): void
    {
        $accountId = DB::table('jnt_accounts')->insertGetId(['label' => $page, 'eccompanyid' => $ec, 'customerid' => 'CU-'.$ec]);
        DB::table('page_jnt_mappings')->insert(['page' => $page, 'jnt_account_id' => $accountId]);
    }

    /** A one-page (or $pages-page) label PDF that names its waybill. */
    private function pdf(string $mailno, int $pages = 1): string
    {
        $p = new Fpdi;
        $p->SetFont('Helvetica', '', 12);
        for ($i = 0; $i < $pages; $i++) {
            $p->AddPage();
            $p->Cell(40, 10, "WAYBILL {$mailno}");
        }

        return $p->Output('S');
    }

    private function macroRow(array $over = []): int
    {
        return DB::table('macro_output')->insertGetId(array_merge([
            'PAGE' => self::PAGE, 'page_name' => self::PAGE, 'FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '09181234567',
            'ADDRESS' => '12 Rizal St', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'PASIG', 'BARANGAY' => 'KAPITOLYO',
            'ITEM_NAME' => '1 x Soap', 'COD' => '499', 'STATUS' => 'PROCEED', 'validate_1' => 1, 'validate_2' => 1,
            'item_checker' => 1, 'ts_date' => self::DATE.' 10:00:00', 'created_at' => self::DATE.' 10:00:00',
        ], $over));
    }

    private function shipment(?string $mailno = null, string $page = self::PAGE): int
    {
        $runId = DB::table('jnt_batch_runs')->insertGetId(['filters' => json_encode(['date' => self::DATE, 'page' => $page]),
            'total' => 1, 'processed' => 0, 'success_count' => 0, 'fail_count' => 0, 'status' => 'running', 'started_at' => now()]);

        return DB::table('jnt_shipments')->insertGetId(['jnt_batch_run_id' => $runId,
            'macro_output_id' => $this->macroRow(['PAGE' => $page]), 'mailno' => $mailno, 'success' => $mailno ? 1 : 0]);
    }

    /** A selected-mode print run of these mailnos, as the controller writes it. */
    private function printRun(array $mailnos): int
    {
        $runId = DB::table('jnt_waybill_print_runs')->insertGetId(['date' => self::DATE, 'mode' => 'selected',
            'filter_by' => 'page', 'filter_value' => self::PAGE, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        foreach (array_values($mailnos) as $i => $m) {
            DB::table('jnt_waybill_print_run_items')->insert(['run_id' => $runId, 'mailno' => $m, 'seq' => $i + 1,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $runId;
    }

    private function runPrint(int $runId): object
    {
        (new BuildJntWaybillBulkPrint($runId))->handle();

        return DB::table('jnt_waybill_print_runs')->find($runId);
    }

    private function outputPages(object $run): int
    {
        return (new Fpdi)->setSourceFile(Storage::disk('local')->path($run->output_path));
    }

    private function keptPath(string $mailno, ?string $day = null): string
    {
        return JntLabelStore::DIR.'/'.($day ?? now('Asia/Manila')->toDateString())."/{$mailno}.pdf";
    }

    /** Records the size of each parallel batch the print run sends. */
    private function spyFetcher(): object
    {
        $spy = new class(app(JntLabelStore::class), app(JntLabelLimiter::class)) extends JntLabelFetcher
        {
            public array $batches = [];

            protected function fetchChunk(array $mailnos, array $pages): array
            {
                $this->batches[] = count($mailnos);

                return parent::fetchChunk($mailnos, $pages);
            }
        };
        $this->app->instance(JntLabelFetcher::class, $spy);

        return $spy;
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'jnt-label', '--stop-when-empty' => true,
            '--sleep' => 0, '--memory' => 4096]);
    }

    // ---- S1: the label is kept after booking ----

    public function test_a_booking_with_a_mailno_queues_the_label_job_on_jnt_label(): void
    {
        Queue::fake();
        $id = $this->shipment();

        (new CreateJntOrder($id))->handle();

        $mailno = (string) DB::table('jnt_shipments')->where('id', $id)->value('mailno');
        $this->assertNotSame('', $mailno);
        Queue::assertPushedOn('jnt-label', FetchJntLabel::class, fn (FetchJntLabel $job) => $job->mailno === $mailno
            && $job->page === self::PAGE && $job->shipmentId === $id);
        $this->assertCount(1, $this->creates);
    }

    public function test_a_failed_booking_queues_no_label_job(): void
    {
        Queue::fake();
        $this->create = 'refused';
        $id = $this->shipment();

        (new CreateJntOrder($id))->handle();

        Queue::assertNotPushed(FetchJntLabel::class);
    }

    public function test_the_label_job_keeps_the_label_under_its_mailno_with_the_page_account(): void
    {
        $id = $this->shipment('730000000001');

        FetchJntLabel::dispatchSync('730000000001', self::PAGE, $id);

        Storage::disk('local')->assertExists($this->keptPath('730000000001'));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($this->keptPath('730000000001')));
        $this->assertSame(['730000000001'], $this->labelCalls);
        $this->assertSame(['EC-PAGE'], $this->labelAccounts);

        // Kept already: no second call.
        FetchJntLabel::dispatchSync('730000000001', self::PAGE, $id);
        $this->assertCount(1, $this->labelCalls);
    }

    public function test_the_label_job_keeps_nothing_for_a_mailno_the_shipment_does_not_have(): void
    {
        $id = $this->shipment('730000000001');

        FetchJntLabel::dispatchSync('730000000999', self::PAGE, $id);

        $this->assertSame([], $this->labelCalls);
        $this->assertNull(app(JntLabelStore::class)->path('730000000999'));
    }

    public function test_an_answer_that_names_another_waybill_is_never_kept(): void
    {
        $this->label = 'other';
        $id = $this->shipment('730000000001');

        FetchJntLabel::dispatchSync('730000000001', self::PAGE, $id);

        $this->assertNull(app(JntLabelStore::class)->path('730000000001'));
        $this->assertNull(app(JntLabelStore::class)->path('730000000777'));
    }

    public function test_no_label_yet_is_tried_after_1_3_and_10_minutes_then_given_up_quietly(): void
    {
        $this->label = 'none';
        $id = $this->shipment('730000000001');
        FetchJntLabel::dispatch('730000000001', self::PAGE, $id)->onConnection('database');

        $delays = [];
        foreach ([60, 180, 600] as $wait) {
            $this->work();
            $job = DB::table('jobs')->first();
            $this->assertNotNull($job, 'released for a later try');
            $this->assertSame('jnt-label', $job->queue);
            $delays[] = $job->available_at - now()->getTimestamp();
            $this->travel($wait + 1)->seconds();
        }
        $this->assertSame([60, 180, 600], $delays);

        $this->work(); // the 4th try: still no label, the job ends quietly

        $this->assertCount(4, $this->labelCalls);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'gives up quietly');
        $this->assertNull(app(JntLabelStore::class)->path('730000000001'));
    }

    public function test_a_label_that_comes_on_the_second_try_is_kept(): void
    {
        $this->label = 'none';
        $id = $this->shipment('730000000001');
        FetchJntLabel::dispatch('730000000001', self::PAGE, $id)->onConnection('database');
        $this->work();

        $this->label = 'pdf';
        $this->travel(61)->seconds();
        $this->work();

        $this->assertCount(2, $this->labelCalls);
        $this->assertNotNull(app(JntLabelStore::class)->path('730000000001'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ---- S2: printing uses kept labels ----

    public function test_a_print_run_with_every_label_kept_calls_jnt_zero_times_and_has_the_same_pages(): void
    {
        $mailnos = [];
        for ($i = 1; $i <= 12; $i++) {
            $mailnos[] = sprintf('7300000001%02d', $i);
        }

        $fetched = $this->runPrint($this->printRun($mailnos)); // nothing kept yet: every label is fetched (and kept)
        $this->assertCount(12, $this->labelCalls);
        $this->assertSame('finished', $fetched->status);

        $this->labelCalls = [];
        $kept = $this->runPrint($this->printRun($mailnos));

        $this->assertSame([], $this->labelCalls, 'zero J&T calls');
        $this->assertSame('finished', $kept->status);
        $this->assertSame([12, 12, 0, 12], [(int) $kept->total, (int) $kept->ok_count, (int) $kept->fail_count, (int) $kept->pages]);
        $this->assertSame((int) $fetched->pages, (int) $kept->pages);
        $this->assertSame('pdf', $kept->output_type);
        $this->assertSame(12, $this->outputPages($kept));
    }

    public function test_a_print_run_fetches_only_the_missing_labels_in_batches_of_at_most_10(): void
    {
        $mailnos = [];
        for ($i = 1; $i <= 25; $i++) {
            $mailnos[] = sprintf('7300000002%02d', $i);
        }
        $store = app(JntLabelStore::class);
        foreach (array_slice($mailnos, 0, 3) as $m) {
            $store->put($m, $this->pdf($m, 2)); // kept labels of 2 pages
        }
        // The mailnos of a mapped page use that page's account; the others the .env account.
        foreach (array_slice($mailnos, 3, 5) as $m) {
            $this->shipment($m);
        }
        $spy = $this->spyFetcher();

        $run = $this->runPrint($this->printRun($mailnos));

        $this->assertEqualsCanonicalizing(array_slice($mailnos, 3), $this->labelCalls, 'only the 22 missing labels');
        $this->assertSame([10, 10, 2], $spy->batches);
        $this->assertSame(5, count(array_filter($this->labelAccounts, fn ($ec) => $ec === 'EC-PAGE')));
        $this->assertSame(17, count(array_filter($this->labelAccounts, fn ($ec) => $ec === 'EC-ENV')));
        $this->assertSame([25, 25, 0, 3 * 2 + 22], [(int) $run->total, (int) $run->ok_count, (int) $run->fail_count, (int) $run->pages]);
        $this->assertSame(28, $this->outputPages($run));
        foreach ($mailnos as $m) {
            $this->assertNotNull($store->path($m), "{$m} is kept for the next run");
        }
    }

    public function test_the_shared_limit_caps_the_calls_in_flight_across_workers(): void
    {
        config(['jnt.labels.max_concurrent' => 20]);
        $held = [];
        for ($i = 0; $i < 15; $i++) { // other workers hold 15 of the 20 slots
            $lock = Cache::lock("jnt-label:slot:{$i}", JntLabelLimiter::SLOT_SECONDS);
            $this->assertTrue($lock->get());
            $held[] = $lock;
        }
        $spy = $this->spyFetcher();
        $mailnos = [];
        for ($i = 1; $i <= 12; $i++) {
            $mailnos[] = sprintf('7300000003%02d', $i);
        }

        $run = $this->runPrint($this->printRun($mailnos));

        $this->assertSame([5, 5, 2], $spy->batches, 'never more than the 5 free slots');
        $this->assertSame(12, (int) $run->ok_count);
        foreach ($held as $lock) {
            $lock->release();
        }
        // Every slot the run took is free again.
        $this->assertCount(20, app(JntLabelLimiter::class)->acquire(20, 0));
    }

    public function test_a_kept_label_that_does_not_parse_is_deleted_and_fetched_once(): void
    {
        Storage::disk('local')->put($this->keptPath('730000000401'), '%PDF-1.4 broken');

        $run = $this->runPrint($this->printRun(['730000000401']));

        $this->assertSame(['730000000401'], $this->labelCalls);
        $this->assertSame([1, 0, 1], [(int) $run->ok_count, (int) $run->fail_count, (int) $run->pages]);
        $this->assertSame('730000000401 | ok', '730000000401 | '.DB::table('jnt_waybill_print_run_items')->where('run_id', $run->id)->value('status'));
    }

    public function test_a_label_jnt_does_not_have_fails_the_item_as_before(): void
    {
        $this->label = 'none';

        $run = $this->runPrint($this->printRun(['730000000501']));

        $this->assertSame('failed', $run->status);
        $this->assertSame('730000000501 | no base64Url',
            DB::table('jnt_waybill_print_run_items')->where('run_id', $run->id)->value('error'));
    }

    // ---- S3: All ready pages ----

    public function test_all_ready_pages_queues_each_ready_page_and_lists_the_others_with_the_reason(): void
    {
        Queue::fake();
        $this->macroRow();
        $this->macroRow(); // ready: 2 orders
        $this->mapPage('Page Shipped', 'EC-S');
        $this->shipment('730000000601', 'Page Shipped'); // its only row is booked already
        $this->mapPage('Page Status', 'EC-T');
        $this->macroRow(['PAGE' => 'Page Status', 'STATUS' => null]);
        $this->mapPage('Page Invalid', 'EC-I');
        $this->macroRow(['PAGE' => 'Page Invalid', 'validate_2' => 0]);
        $this->macroRow(['PAGE' => 'Page No Account']);
        $this->macroRow(['PAGE' => 'Page Other Day', 'ts_date' => '2026-11-11 10:00:00']);

        $resp = $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => JntOrderUiController::ALL_PAGES]);

        $resp->assertRedirect(url('/jnt/orders').'?date='.self::DATE.'&page='.JntOrderUiController::ALL_PAGES);
        $resp->assertSessionHas('success', 'All ready pages: 1 page(s) queued, 2 order(s). 4 page(s) skipped.');
        $summary = session('batch_summary');
        $run = DB::table('jnt_batch_runs')->orderByDesc('id')->first();
        $this->assertSame([['page' => self::PAGE, 'run_id' => $run->id, 'total' => 2]], $summary['queued']);
        $this->assertSame(2, (int) $run->total);
        $this->assertSame(['date' => self::DATE, 'page' => self::PAGE], json_decode($run->filters, true));
        $reasons = collect($summary['skipped'])->pluck('reason', 'page')->all();
        $this->assertSame(['Page Invalid', 'Page No Account', 'Page Shipped', 'Page Status'], array_keys($reasons));
        $this->assertStringStartsWith('VALIDATION INCOMPLETE', $reasons['Page Invalid']);
        $this->assertStringStartsWith('No JNT Account mapped', $reasons['Page No Account']);
        $this->assertStringStartsWith('No new orders to batch', $reasons['Page Shipped']);
        $this->assertStringStartsWith('STATUS INCOMPLETE', $reasons['Page Status']);
        Queue::assertPushed(CreateJntOrder::class, 2);

        // A second click books nothing twice: the page's orders have shipments now.
        $again = $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => JntOrderUiController::ALL_PAGES]);
        $again->assertSessionHas('error', 'All ready pages: no page was ready. 5 page(s) skipped.');
        Queue::assertPushed(CreateJntOrder::class, 2);
        $this->assertSame(1, DB::table('jnt_batch_runs')->where('filters', 'like', '%'.self::PAGE.'%')->count());
    }

    public function test_a_single_page_works_as_before(): void
    {
        Queue::fake();
        $this->macroRow();
        $this->macroRow(['PAGE' => 'Page Status', 'STATUS' => null]);
        $this->mapPage('Page Status', 'EC-T');

        $ok = $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => self::PAGE]);
        $runId = DB::table('jnt_batch_runs')->value('id');
        $ok->assertRedirect(url('/jnt/orders').'?date='.self::DATE.'&page='.urlencode(self::PAGE));
        $ok->assertSessionHas('success', "Batch created. Run #{$runId} with 1 shipments queued.");
        $this->assertFalse(session()->has('batch_summary'));

        $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => 'Page Status'])
            ->assertSessionHas('error', 'STATUS INCOMPLETE: 1 missing STATUS out of 1 rows. Fill STATUS for ALL rows first.');
        $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => ''])
            ->assertSessionHas('error', 'Please select a Page first.');
        $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => self::PAGE])
            ->assertSessionHas('error', 'No new orders to batch. All PROCEED rows already have shipment records. Use "Retry Failed" for failed ones.');

        Queue::assertPushed(CreateJntOrder::class, 1);
    }

    public function test_a_page_being_batched_by_another_click_is_not_queued_twice(): void
    {
        Queue::fake();
        $this->macroRow();
        $lock = Cache::lock('jnt-batch:'.md5(self::DATE.'|'.self::PAGE), 120);
        $this->assertTrue($lock->get());

        $this->post('/jnt/orders/batch', ['date' => self::DATE, 'page' => self::PAGE])
            ->assertSessionHas('error', 'A batch for "'.self::PAGE.'" on '.self::DATE.' is being created right now. Refresh in a moment.');

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('jnt_batch_runs')->count());
        $lock->release();
    }

    public function test_the_dropdown_offers_all_ready_pages_and_keeps_it_selected(): void
    {
        $this->macroRow();

        $html = $this->get('/jnt/orders?date='.self::DATE.'&page='.JntOrderUiController::ALL_PAGES)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.JntOrderUiController::ALL_PAGES.'"\s+selected[^>]*>— All ready pages —<\/option>/', $html);
        $this->assertStringNotContainsString('No account mapped', $html);
        $this->assertDoesNotMatchRegularExpression('/Create Batch \(Queue\)[\s\S]{0,20}disabled|disabled[^>]*>\s*Create Batch/', $html);
    }

    // ---- S4: prune ----

    public function test_prune_removes_only_labels_older_than_14_days(): void
    {
        $disk = Storage::disk('local');
        $today = now('Asia/Manila')->startOfDay();
        foreach ([0, 1, 14, 15, 30] as $ago) {
            $disk->put($this->keptPath("73000000{$ago}", $today->copy()->subDays($ago)->toDateString()), '%PDF-1.4');
        }
        $disk->put(JntLabelStore::DIR.'/notes/keep.txt', 'x');

        $this->artisan('jnt:prune-labels')->assertSuccessful();

        foreach ([0, 1, 14] as $ago) {
            $disk->assertExists($this->keptPath("73000000{$ago}", $today->copy()->subDays($ago)->toDateString()));
        }
        foreach ([15, 30] as $ago) {
            $this->assertFalse(File::isDirectory($disk->path(JntLabelStore::DIR.'/'.$today->copy()->subDays($ago)->toDateString())));
        }
        $disk->assertExists(JntLabelStore::DIR.'/notes/keep.txt');
    }

    public function test_the_label_queue_and_the_prune_are_wired(): void
    {
        $this->assertSame('jnt-label', (new FetchJntLabel('7300'))->queue);
        $this->assertSame(4, (new FetchJntLabel('7300'))->tries);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'jnt:prune-labels'));
        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * *', $events->first()->expression);
    }
}
