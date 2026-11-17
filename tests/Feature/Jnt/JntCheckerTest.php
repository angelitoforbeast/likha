<?php

namespace Tests\Feature\Jnt;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Jobs\RunJntCheckerUpdate;
use App\Jobs\RunJntCheckerUpload;
use App\Models\User;
use App\Services\Jnt\JntCheckerRuns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Auth\AuthTestCase;
use Tests\Feature\Jnt\Legacy\LegacyJntCheckerController;

/**
 * Spec 283: ang /jnt/checker Upload at Update ay hindi na nagwe-504. Ang mabigat na trabaho ay nasa queue
 * job (queue "default"); ang page ay nagbabasa ng estado. Ang resulta ay dapat kapareho ng lumang code
 * (LegacyJntCheckerController, kopya ng 16dade83).
 */
class JntCheckerTest extends AuthTestCase
{
    private const HOST = 'http://likha.test';
    private const HEADER = ['Waybill No', 'Sender Name', 'Receiver Name', 'Receiver Cellphone', 'Item Name', 'COD', 'Order Status', 'Total Shipping Cost'];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [
                'database/migrations/2025_07_30_233344_create_page_sender_mappings_table.php',
                'database/migrations/2025_12_13_222911_add_unique_sender_name_to_page_sender_mappings.php',
                'database/migrations/2026_04_10_000001_add_item_name_to_page_sender_mappings.php',
                'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
                'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
            ],
            '--realpath' => false,
        ]);

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('PHONE NUMBER')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('waybill')->nullable();
            $t->timestamps();
        });

        // MySQL functions na ginagamit ng date filter, para sa sqlite ng test.
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('RIGHT', fn ($s, $n) => $s === null ? null : substr((string) $s, -((int) $n)), 2);
        $pdo->sqliteCreateFunction('STR_TO_DATE', function ($s, $fmt) {
            $d = \DateTime::createFromFormat('!d-m-Y', (string) $s);
            return $d ? $d->format('Y-m-d') : null;
        }, 2);

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jnt283_' . uniqid();
        @mkdir($this->tmp . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app', 0775, true);
        $this->app->useStoragePath($this->tmp . DIRECTORY_SEPARATOR . 'storage');

        $this->withoutMiddleware(AllowedIpMiddleware::class);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    // ================================================================
    // RU-E: the results are identical to the current code's
    // ================================================================

    public function test_RU_E_upload_results_are_identical_to_the_old_code_for_an_xlsx_and_a_date_range(): void
    {
        $this->seedRegressionData();
        $file = $this->xlsx('jnt.xlsx', $this->regressionRows());

        $this->assertSameAsLegacy($file, 'jnt.xlsx', '2026-11-05', '2026-11-06');
    }

    public function test_RU_E_upload_results_are_identical_to_the_old_code_for_a_zip_and_a_single_date(): void
    {
        $this->seedRegressionData();
        $rows = $this->regressionRows();
        $a = $this->xlsx('part-a.xlsx', array_slice($rows, 0, 7));
        $b = $this->xlsx('part-b.xlsx', array_slice($rows, 7));
        $zip = $this->zip('jnt.zip', ['part-a.xlsx' => $a, 'sub/part-b.xlsx' => $b]);

        $this->assertSameAsLegacy($zip, 'jnt.zip', '2026-11-05', null);
    }

    // ================================================================
    // RU-H / RS: the request only stores and queues; the page polls
    // ================================================================

    public function test_RU_H_RS_upload_answers_at_once_runs_in_the_default_queue_then_shows_the_results(): void
    {
        $user = $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();

        DB::enableQueryLog();
        $res = $this->post(self::HOST . '/jnt/checker/upload', [
            'excel_file' => $this->upload($this->xlsx('jnt.xlsx', $this->regressionRows()), 'jnt.xlsx'),
            'filter_date_start' => '2026-11-05',
            'filter_date_end' => '2026-11-06',
        ]);
        $heavy = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($q) => preg_match('/macro_output|page_sender_mappings|fee_settings/', $q))->values()->all();
        DB::disableQueryLog();

        $runId = $this->runIdFrom($res);
        $this->assertSame([], $heavy, 'the upload request must not read orders, mappings or fees');
        Queue::assertPushedOn('default', RunJntCheckerUpload::class, fn ($job) => $job->runId === $runId && $job->host === 'likha.test');
        $this->assertFileExists(app(JntCheckerRuns::class)->workDir($runId) . DIRECTORY_SEPARATOR . 'upload.xlsx');

        $this->get('/jnt/checker?run=' . $runId)->assertOk()->assertSee('Waiting to start')
            ->assertSee('data-status-url="' . route('jnt.checker.status', ['run' => $runId]) . '"', false)
            ->assertDontSee('UPDATE ORDERS (set WAYBILL)');
        $this->getJson('/jnt/checker/status/' . $runId)->assertOk()->assertJson(['status' => 'queued']);

        $this->runQueuedUpload($runId);

        $this->getJson('/jnt/checker/status/' . $runId)->assertJson(['status' => 'done']);
        $page = $this->get('/jnt/checker?run=' . $runId)->assertOk()->assertDontSee('Waiting to start')
            ->assertSee('UPDATE ORDERS (set WAYBILL)')->assertSee('name="run" value="' . $runId . '"', false);
        $this->assertGreaterThan(0, $page->viewData('matchedCount'));
        // the session remembers the user's last run, so /jnt/checker alone shows it too
        $this->get('/jnt/checker')->assertOk()->assertSee('UPDATE ORDERS (set WAYBILL)');
        // the uploaded file and the extracted folder are gone, the result file stays
        $this->assertDirectoryDoesNotExist(app(JntCheckerRuns::class)->workDir($runId));
        $this->assertFileExists(storage_path('app/jnt_checker/' . $runId . '.json'));
        $this->assertSame($user->id, app(JntCheckerRuns::class)->get($runId)['user_id']);
    }

    public function test_RU_H_a_running_check_shows_running_and_a_stale_one_shows_failed_not_a_spinner(): void
    {
        $this->signedIn();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $runs = app(JntCheckerRuns::class);

        $runs->patch($runId, ['status' => 'running', 'started_at' => now()->timestamp]);
        $this->get('/jnt/checker?run=' . $runId)->assertSee('Checking the orders');

        $runs->patch($runId, ['started_at' => now()->timestamp - 1000]);
        $this->get('/jnt/checker?run=' . $runId)->assertDontSee('Checking the orders')
            ->assertSee('The check stopped: it took longer than 10 minutes.');
        $this->getJson('/jnt/checker/status/' . $runId)->assertJson(['status' => 'failed']);
    }

    public function test_RU_H_a_failed_job_shows_a_failed_state_with_a_short_reason(): void
    {
        $this->signedIn();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));

        $job = Queue::pushed(RunJntCheckerUpload::class)->first();
        $job->failed(new \RuntimeException('Allowed memory size exhausted'));

        $this->get('/jnt/checker?run=' . $runId)->assertOk()
            ->assertSee('The check stopped: Allowed memory size exhausted')->assertDontSee('Waiting to start');
        $this->assertDirectoryDoesNotExist(app(JntCheckerRuns::class)->workDir($runId));
    }

    // ================================================================
    // RU-N: same error messages as today
    // ================================================================

    public function test_RU_N_bad_file_type_is_refused_by_the_same_validation(): void
    {
        $this->signedIn();
        Queue::fake();

        $this->from('/jnt/checker')->post(self::HOST . '/jnt/checker/upload', [
            'excel_file' => UploadedFile::fake()->createWithContent('jnt.csv', "a,b\n1,2\n"),
            'filter_date_start' => '2026-11-05',
        ])->assertSessionHasErrors('excel_file');

        $this->from('/jnt/checker')->post(self::HOST . '/jnt/checker/upload', [
            'excel_file' => $this->upload($this->xlsx('jnt.xlsx', []), 'jnt.xlsx'),
        ])->assertSessionHasErrors('filter_date_start');

        Queue::assertNothingPushed();
    }

    public function test_RU_N_zip_problems_answer_at_once_with_todays_messages(): void
    {
        $this->signedIn();
        Queue::fake();

        $noExcel = $this->zip('none.zip', ['readme.txt' => $this->textFile('readme.txt', 'hi')]);
        $this->from('/jnt/checker')->post(self::HOST . '/jnt/checker/upload', [
            'excel_file' => $this->upload($noExcel, 'none.zip'), 'filter_date_start' => '2026-11-05',
        ])->assertRedirect('/jnt/checker')->assertSessionHas('error', 'ZIP contains no .xlsx or .xls files.');

        // starts like a zip (passes the same mimes rule) but has no central directory
        $whole = (string) file_get_contents($this->xlsx('whole.xlsx', $this->regressionRows()));
        $broken = $this->textFile('broken.zip', substr($whole, 0, 200));
        $this->from('/jnt/checker')->post(self::HOST . '/jnt/checker/upload', [
            'excel_file' => $this->upload($broken, 'broken.zip'), 'filter_date_start' => '2026-11-05',
        ])->assertRedirect('/jnt/checker')->assertSessionHas('error', 'Failed to open ZIP file.');

        Queue::assertNothingPushed();
    }

    public function test_RU_N_unreadable_file_and_missing_header_show_todays_messages_on_the_page(): void
    {
        $this->signedIn();

        $cases = [
            // a plain zip named .xlsx passes the same mimes rule as today, then cannot be read as a workbook
            ['Failed to read Excel file.', $this->zip('bad.xlsx', ['note.txt' => $this->textFile('note.txt', 'hi')]), 'bad.xlsx'],
            ['Missing column(s): COD, Order Status', $this->xlsx('cols.xlsx', [['WB1', 'S', 'N', '09171234567', 'Item']], ['Waybill No', 'Sender Name', 'Receiver Name', 'Receiver Cellphone', 'Item Name']), 'cols.xlsx'],
            ['File bad.xlsx: Failed to read Excel file.', $this->zip('z.zip', ['bad.xlsx' => $this->zip('inner.xlsx', ['note.txt' => $this->textFile('note2.txt', 'hi')])]), 'z.zip'],
            ['File notes.xlsx: Missing column(s): Sender Name, Receiver Cellphone, Item Name, COD, Order Status', $this->zip('y.zip', ['notes.xlsx' => $this->textFile('notes.xlsx', 'nope')]), 'y.zip'],
        ];

        foreach ($cases as [$message, $path, $name]) {
            $runId = $this->runIdFrom($this->postUpload($path, $name)); // sync queue: the job runs here
            $this->get('/jnt/checker?run=' . $runId)->assertOk()->assertSee($message)
                ->assertDontSee('Waiting to start')->assertDontSee('UPDATE ORDERS (set WAYBILL)');
            $this->assertDirectoryDoesNotExist(app(JntCheckerRuns::class)->workDir($runId));
        }
    }

    public function test_RU_N_all_cancel_rows_show_todays_success_message(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        $rows = [['WB1', 'Shop A Sender', 'Ana', '09170000001', 'Soap', '299', 'Cancel Order', '120']];
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('c.xlsx', $rows)));

        $this->get('/jnt/checker?run=' . $runId)->assertOk()
            ->assertSee('Uploaded successfully, but all rows were filtered out (Cancel Order).');
    }

    // ================================================================
    // RU-E: two staff at the same time; double submit
    // ================================================================

    public function test_RU_E_two_staff_each_see_only_their_own_results_and_update_their_own_rows(): void
    {
        $this->seedRegressionData();
        $rows = $this->regressionRows();
        $ana = $this->user('Data Encoder', 'ana@example.test');
        $ben = $this->user('Data Encoder', 'ben@example.test');

        Queue::fake();
        $this->actingAs($ana);
        $runA = $this->runIdFrom($this->postUpload($this->xlsx('a.xlsx', [$rows[0]]), 'a.xlsx'));
        $this->actingAs($ben);
        $runB = $this->runIdFrom($this->postUpload($this->xlsx('b.xlsx', [$rows[1]]), 'b.xlsx'));
        $this->runQueuedUpload($runA);
        $this->runQueuedUpload($runB);

        $this->actingAs($ana);
        $this->get('/jnt/checker?run=' . $runA)->assertOk()->assertSee('WB-1001')->assertDontSee('WB-1002');
        $this->get('/jnt/checker?run=' . $runB)->assertNotFound();
        $this->getJson('/jnt/checker/status/' . $runB)->assertNotFound();
        $this->post('/jnt/checker/update', ['run' => $runB])->assertNotFound();

        $this->post('/jnt/checker/update', ['run' => $runA])->assertRedirect();
        $this->runQueuedUpdate($runA);

        $this->assertSame(['WB-1001'], DB::table('macro_output')->whereNotNull('waybill')->pluck('waybill')->all());

        $this->actingAs($ben);
        $this->post('/jnt/checker/update', ['run' => $runB])->assertRedirect();
        $this->runQueuedUpdate($runB);
        $this->assertEqualsCanonicalizing(['WB-1001', 'WB-1002'], DB::table('macro_output')->whereNotNull('waybill')->pluck('waybill')->all());
    }

    public function test_RU_E_double_submit_of_the_same_upload_makes_one_run_and_one_job(): void
    {
        $this->signedIn();
        Queue::fake();
        $file = $this->xlsx('jnt.xlsx', $this->regressionRows());

        $first = $this->runIdFrom($this->postUpload($file));
        $second = $this->runIdFrom($this->postUpload($file));

        $this->assertSame($first, $second);
        Queue::assertPushed(RunJntCheckerUpload::class, 1);

        // after the first is done, uploading the same file again is a new check (e.g. after a mapping fix)
        $this->runQueuedUpload($first);
        $third = $this->runIdFrom($this->postUpload($file));
        $this->assertNotSame($first, $third);
    }

    // ================================================================
    // RR: Update
    // ================================================================

    public function test_RR_H_update_runs_in_the_default_queue_writes_every_matched_row_and_shows_the_count(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $this->runQueuedUpload($runId);
        $expected = app(JntCheckerRuns::class)->readResult($runId)['updatable'];
        $this->assertNotEmpty($expected);

        DB::enableQueryLog();
        $this->post('/jnt/checker/update', ['run' => $runId])->assertRedirect('/jnt/checker?run=' . $runId);
        $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'macro_output'))->values()->all());
        DB::disableQueryLog();

        Queue::assertPushedOn('default', RunJntCheckerUpdate::class);
        $this->get('/jnt/checker?run=' . $runId)->assertSee('Update waiting to start')->assertDontSee('UPDATE ORDERS (set WAYBILL)');
        $this->getJson('/jnt/checker/status/' . $runId)->assertJson(['status' => 'done', 'update' => ['status' => 'queued']]);

        $this->runQueuedUpdate($runId);

        foreach ($expected as $u) {
            $this->assertSame($u['waybill'], DB::table('macro_output')->where('id', $u['id'])->value('waybill'));
        }
        $count = count($expected);
        $this->get('/jnt/checker?run=' . $runId)->assertSee("WAYBILL updated for {$count} row(s).")
            ->assertDontSee('UPDATE ORDERS (set WAYBILL)');
        $this->assertSame($count, DB::table('macro_output')->whereNotNull('waybill')->count());
    }

    public function test_RR_N_nothing_updatable_gives_todays_message(): void
    {
        $this->signedIn();
        Queue::fake();

        // no run at all
        $this->from('/jnt/checker')->post('/jnt/checker/update')
            ->assertSessionHas('error', 'No matched rows with WAYBILL found from the last upload.');

        // a run whose rows match nothing
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', [['WB9', 'Unknown Sender', 'X', '0917', 'Y', '1', 'Delivered', '1']])));
        $this->runQueuedUpload($runId);
        $this->post('/jnt/checker/update', ['run' => $runId])
            ->assertSessionHas('error', 'No matched rows with WAYBILL found from the last upload.');
        Queue::assertNotPushed(RunJntCheckerUpdate::class);
    }

    public function test_RR_E_double_click_and_two_tabs_run_one_update_and_report_the_count_once(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $this->runQueuedUpload($runId);

        $this->post('/jnt/checker/update', ['run' => $runId]);
        $this->post('/jnt/checker/update', ['run' => $runId]);
        Queue::assertPushed(RunJntCheckerUpdate::class, 1);

        $this->runQueuedUpdate($runId);
        $this->post('/jnt/checker/update', ['run' => $runId]); // third click after it finished
        Queue::assertPushed(RunJntCheckerUpdate::class, 1);

        // a second copy of the job (e.g. a stray retry) does nothing and keeps the first count
        $count = app(JntCheckerRuns::class)->getUpdate($runId)['count'];
        (new RunJntCheckerUpdate($runId))->handle(app(JntCheckerRuns::class));
        $this->assertSame($count, app(JntCheckerRuns::class)->getUpdate($runId)['count']);
        $this->get('/jnt/checker?run=' . $runId)->assertSee("WAYBILL updated for {$count} row(s).");
    }

    public function test_RR_E_a_matched_row_deleted_before_update_is_skipped_and_not_counted(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $this->runQueuedUpload($runId);
        $updatable = app(JntCheckerRuns::class)->readResult($runId)['updatable'];

        DB::table('macro_output')->where('id', $updatable[0]['id'])->delete();

        $this->post('/jnt/checker/update', ['run' => $runId]);
        $this->runQueuedUpdate($runId);

        $written = count($updatable) - 1;
        $this->assertSame($written, app(JntCheckerRuns::class)->getUpdate($runId)['count']);
        $this->get('/jnt/checker?run=' . $runId)->assertSee("WAYBILL updated for {$written} row(s).");
    }

    public function test_RR_a_failed_update_shows_the_reason_and_can_be_tried_again(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $this->runQueuedUpload($runId);

        $this->post('/jnt/checker/update', ['run' => $runId]);
        (new RunJntCheckerUpdate($runId))->failed(new \RuntimeException('Lock wait timeout exceeded'));

        $this->get('/jnt/checker?run=' . $runId)->assertSee('The update stopped: Lock wait timeout exceeded')
            ->assertSee('TRY THE UPDATE AGAIN');

        $this->post('/jnt/checker/update', ['run' => $runId]);
        Queue::assertPushed(RunJntCheckerUpdate::class, 2);
        $this->runQueuedUpdate($runId);
        $this->get('/jnt/checker?run=' . $runId)->assertSee('WAYBILL updated for');
    }

    public function test_RR_update_writes_in_batches_of_at_most_500_ids_per_statement(): void
    {
        DB::table('macro_output')->insert(array_map(fn ($i) => ['PAGE' => 'P', 'STATUS' => 'PROCEED'], range(1, 1201)));
        $updatable = array_map(fn ($i) => ['id' => $i, 'waybill' => 'WB' . intdiv($i, 3)], range(1, 1201));

        DB::enableQueryLog();
        $count = (new RunJntCheckerUpdate('00000000-0000-0000-0000-000000000000'))->write($updatable);
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'update'))->values();
        DB::disableQueryLog();

        $this->assertSame(1201, $count);
        $this->assertCount(3, $updates); // 500 + 500 + 201
        $this->assertSame('WB400', DB::table('macro_output')->where('id', 1201)->value('waybill'));
        $this->assertNotNull(DB::table('macro_output')->where('id', 1)->value('updated_at'));
    }

    // ================================================================
    // Expired: never an error page
    // ================================================================

    public function test_expired_state_or_missing_result_file_shows_the_expired_message(): void
    {
        $this->signedIn();
        $this->seedRegressionData();
        Queue::fake();
        $runId = $this->runIdFrom($this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows())));
        $this->runQueuedUpload($runId);

        @unlink(storage_path('app/jnt_checker/' . $runId . '.json'));
        $this->get('/jnt/checker?run=' . $runId)->assertOk()->assertSee('This check expired, please upload again.');
        $this->post('/jnt/checker/update', ['run' => $runId])->assertRedirect('/jnt/checker?run=' . $runId);
        Queue::assertNotPushed(RunJntCheckerUpdate::class);

        Cache::flush();
        $this->get('/jnt/checker?run=' . $runId)->assertOk()->assertSee('This check expired, please upload again.');
        $this->getJson('/jnt/checker/status/' . $runId)->assertOk()->assertJson(['status' => 'expired']);
        $this->get('/jnt/checker?run=not-a-uuid')->assertOk()->assertSee('This check expired, please upload again.');
        $this->get('/jnt/checker/status/not-a-uuid')->assertNotFound();
    }

    public function test_result_files_and_leftover_upload_folders_older_than_24_hours_are_deleted_on_a_new_run(): void
    {
        $this->signedIn();
        Queue::fake();
        $base = JntCheckerRuns::baseDir();
        @mkdir($base . '/old-run', 0775, true);
        file_put_contents($base . '/old-run/upload.xlsx', 'x');
        file_put_contents($base . '/old.json', '{}');
        file_put_contents($base . '/fresh.json', '{}');
        touch($base . '/old.json', time() - 90000);
        touch($base . '/old-run', time() - 90000);

        $this->postUpload($this->xlsx('jnt.xlsx', $this->regressionRows()));

        $this->assertFileDoesNotExist($base . '/old.json');
        $this->assertDirectoryDoesNotExist($base . '/old-run');
        $this->assertFileExists($base . '/fresh.json');
    }

    public function test_RS_the_checker_routes_are_the_known_light_ones(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'jnt/checker'))
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri() . ' ' . $r->getActionName())
            ->sort()->values()->all();

        $this->assertSame([
            'GET|HEAD jnt/checker App\Http\Controllers\JntCheckerController@index',
            'GET|HEAD jnt/checker/status/{run} App\Http\Controllers\JntCheckerController@status',
            'GET|HEAD jnt/checker/upload Closure',
            'POST jnt/checker/update App\Http\Controllers\JntCheckerController@update',
            'POST jnt/checker/upload App\Http\Controllers\JntCheckerController@upload',
        ], $routes);
    }

    // ================================================================
    // Done-when 3: timing on a seeded fixture (5,000+ rows, 2,000+ waybills)
    // ================================================================

    public function test_timing_5000_matched_rows_across_2000_waybills(): void
    {
        $this->signedIn();
        DB::table('page_sender_mappings')->insert(['SENDER_NAME' => 'Big Sender', 'PAGE' => 'Big Page']);
        DB::table('fee_settings')->insert(['setting_key' => 'shipping_fee_per_order', 'setting_value' => 120, 'effective_date' => '2026-11-01', 'host_scope' => 'likha']);

        $rows = [];
        $macro = [];
        $n = 0;
        for ($w = 1; $w <= 2000; $w++) {
            $items = $w <= 1000 ? 3 : 2; // 1,000 x 3 + 1,000 x 2 = 5,000 rows
            for ($i = 1; $i <= $items; $i++) {
                $n++;
                $phone = '0917' . str_pad((string) $w, 7, '0', STR_PAD_LEFT);
                $rows[] = ['WB' . str_pad((string) $w, 9, '0', STR_PAD_LEFT), 'Big Sender', 'Customer ' . $w, $phone, 'Item ' . $i, '299', 'Delivered', '120'];
                $macro[] = ['PAGE' => 'Big Page', 'FULL NAME' => 'Customer ' . $w, 'PHONE NUMBER' => $phone, 'ITEM_NAME' => 'Item ' . $i,
                    'COD' => '299', 'TIMESTAMP' => '10:00:00 05-11-2026', 'STATUS' => 'PROCEED'];
            }
        }
        foreach (array_chunk($macro, 500) as $chunk) DB::table('macro_output')->insert($chunk);
        $file = $this->xlsx('big.xlsx', $rows);

        Queue::fake();
        $t = microtime(true);
        $runId = $this->runIdFrom($this->postUpload($file, 'big.xlsx'));
        $uploadRequest = microtime(true) - $t;

        $t = microtime(true);
        $this->runQueuedUpload($runId);
        $uploadJob = microtime(true) - $t;

        $t = microtime(true);
        $page = $this->get('/jnt/checker?run=' . $runId)->assertOk();
        $resultsPage = microtime(true) - $t;
        $this->assertSame(5000, $page->viewData('matchedCount'));
        $this->assertSame(5000, $page->viewData('updatableCount'));

        $t = microtime(true);
        $this->post('/jnt/checker/update', ['run' => $runId])->assertRedirect();
        $updateRequest = microtime(true) - $t;

        $t = microtime(true);
        $this->runQueuedUpdate($runId);
        $updateJob = microtime(true) - $t;

        $this->assertSame(5000, DB::table('macro_output')->whereNotNull('waybill')->count());
        $this->assertSame(2000, DB::table('macro_output')->distinct()->count('waybill'));
        $this->assertSame(5000, app(JntCheckerRuns::class)->getUpdate($runId)['count']);
        $this->assertLessThan(10, $uploadRequest);
        $this->assertLessThan(10, $updateRequest);

        fwrite(STDOUT, sprintf(
            "\n[283 timing] 5,000 rows / 2,000 waybills: upload request %.2f s, upload job %.2f s, results page %.2f s, update request %.2f s, update job %.2f s\n",
            $uploadRequest, $uploadJob, $resultsPage, $updateRequest, $updateJob
        ));
    }

    // ================================================================
    // helpers
    // ================================================================

    private function assertSameAsLegacy(string $path, string $name, string $start, ?string $end): void
    {
        $user = $this->signedIn();
        $keys = ['results', 'matchedCount', 'notMatchedCount', 'notInExcelCount', 'filter_date_start', 'filter_date_end',
            'updatableCount', 'mappingMissingCount', 'skippedCancelCount', 'processedFilesCount', 'perfectMatch',
            'sfMismatchCount', 'sfCorrectCount', 'sfNoDataCount', 'expectedShippingFee'];

        // old code
        $request = Request::create(self::HOST . '/jnt/checker/upload', 'POST',
            array_filter(['filter_date_start' => $start, 'filter_date_end' => $end], fn ($v) => $v !== null),
            [], ['excel_file' => $this->upload($this->copy($path), $name)]);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $user);
        app(LegacyJntCheckerController::class)->upload($request);
        $session = $this->app['session.store'];
        $old = [];
        foreach ($keys as $k) $old[$k] = $session->get($k);
        $old['notInExcelRows'] = $session->get('notInExcelRows')->map(fn ($m) => $m->getAttributes())->all();
        $old['updatable'] = $session->get('jnt_updatable');
        $this->assertGreaterThan(0, $old['matchedCount']);
        $this->assertGreaterThan(0, $old['notMatchedCount']);
        $this->assertGreaterThan(0, $old['notInExcelCount']);
        $this->assertGreaterThan(0, $old['mappingMissingCount']);
        $this->assertGreaterThan(0, $old['skippedCancelCount']);
        $this->assertGreaterThan(0, $old['sfMismatchCount']);

        // new code, through the real request, the queue job (sync) and the results page
        $runId = $this->runIdFrom($this->postUpload($this->copy($path), $name, $start, $end));
        $page = $this->get('/jnt/checker?run=' . $runId)->assertOk();
        $new = [];
        foreach ($keys as $k) $new[$k] = $page->viewData($k);
        $new['notInExcelRows'] = $page->viewData('notInExcelRows')->map(fn ($o) => (array) $o)->all();
        $new['updatable'] = app(JntCheckerRuns::class)->readResult($runId)['updatable'];

        $this->assertSame($old, $new);
    }

    /** Macro rows + mapping + fee para sa lahat ng kaso ng pagtutugma. */
    private function seedRegressionData(): void
    {
        DB::table('page_sender_mappings')->insert([
            ['SENDER_NAME' => 'Shop A Sender', 'PAGE' => 'Shop A'],
            ['SENDER_NAME' => 'Shop B Sender', 'PAGE' => 'Shop B'],
        ]);
        DB::table('fee_settings')->insert(['setting_key' => 'shipping_fee_per_order', 'setting_value' => 120, 'effective_date' => '2026-11-01', 'host_scope' => 'likha']);

        $m = fn ($page, $name, $phone, $item, $cod, $date, $status = 'PROCEED') => [
            'PAGE' => $page, 'FULL NAME' => $name, 'PHONE NUMBER' => $phone, 'ITEM_NAME' => $item,
            'COD' => $cod, 'TIMESTAMP' => '10:15:00 ' . $date, 'STATUS' => $status,
        ];
        DB::table('macro_output')->insert([
            $m('Shop A', 'Ana Cruz', '09170000001', 'Soap', '299', '05-11-2026'),          // 1 plain match
            $m('Shop A', 'Ben Reyes', '09170000002', 'Soap', '299', '05-11-2026'),         // 2 match via 63 phone
            $m('Shop B', 'Cara Dee', '09170000003', 'Lotion', '499.00', '06-11-2026'),     // 3 match (only in range)
            $m('Shop A', 'Dan Uy', '09170000004', 'Soap', '299', '05-11-2026'),            // 4 duplicate key, in order
            $m('Shop A', 'Dan Uy', '09170000004', 'Soap', '299', '05-11-2026'),            // 5 duplicate key
            $m('Shop A', 'Eva Lim', '09999999999', 'Cream', '199', '05-11-2026'),          // 6 shared phone, name match
            $m('Shop A', 'Fe Tan', '09999999999', 'Cream', '199', '05-11-2026'),           // 7 shared phone, not in Excel
            $m('Shop A', 'Gil Go', '09170000008', 'Soap', '299', '05-11-2026'),            // 8 not in Excel
            $m('Shop A', 'Hana Ko', '09170000009', 'Soap', '299', '05-11-2026', 'CANCEL'), // 9 not PROCEED
            $m('Shop A', 'Ivy Sy', '09170000010', 'Soap', '299', '09-11-2026'),            // 10 out of range
            $m('Shop A', 'Jun Ong', '09170000011', 'Soap', '299', '05-11-2026'),           // 11 match, no waybill in Excel
        ]);
    }

    private function regressionRows(): array
    {
        return [
            ['WB-1001', 'Shop A Sender', 'Ana Cruz', '09170000001', 'Soap', '299', 'Delivered', '120'],
            ['WB-1002', 'shop a sender ', 'Ben Reyes', '639170000002', 'Soap', '₱299.00', 'Delivered', '135'],
            ['WB-1003', 'Shop B Sender', 'Cara Dee', '9170000003', 'Lotion', '499', 'In Transit', ''],
            ['WB-1004', 'Shop A Sender', 'Dan Uy', '09170000004', 'Soap', '299', 'Delivered', '120.00'],
            ['WB-1005', 'Shop A Sender', 'Dan Uy', '09170000004', 'Soap', '299', 'Delivered', '120'],
            ['WB-1006', 'Shop A Sender', 'Dan Uy', '09170000004', 'Soap', '299', 'Delivered', '120'],       // duplicates exhausted
            ['WB-1007', 'Shop A Sender', 'Eva Lim', '09999999999', 'Cream', '199', 'Delivered', '120'],
            ['WB-1008', 'Shop A Sender', 'Zed Wrong', '09999999999', 'Cream', '199', 'Delivered', '120'],   // shared phone, no name
            ['WB-1009', 'Unknown Sender', 'Kim', '09170000012', 'Soap', '299', 'Delivered', '120'],         // mapping missing
            ['WB-1010', 'Shop A Sender', 'Lea', '09170000013', 'Soap', '299', ' cancel  ORDER ', '120'],    // cancel skipped
            ['',        'Shop A Sender', 'Jun Ong', '09170000011', 'Soap', '299', 'Delivered', '120'],      // matched, no waybill
            ['WB-1012', 'Shop A Sender', 'Ivy Sy', '09170000010', 'Soap', '299', 'Delivered', '120'],       // macro out of range
        ];
    }

    private function signedIn(): User
    {
        $user = $this->user('Data Encoder');
        $this->actingAs($user);

        return $user;
    }

    private function postUpload(string $path, string $name = 'jnt.xlsx', string $start = '2026-11-05', ?string $end = '2026-11-06')
    {
        return $this->post(self::HOST . '/jnt/checker/upload', array_filter([
            'excel_file' => $this->upload($this->copy($path), $name),
            'filter_date_start' => $start,
            'filter_date_end' => $end,
        ], fn ($v) => $v !== null));
    }

    private function runIdFrom($response): string
    {
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertTrue(JntCheckerRuns::isValidId($q['run'] ?? null), 'redirect carries the run id');

        return $q['run'];
    }

    private function runQueuedUpload(string $runId): void
    {
        $job = Queue::pushed(RunJntCheckerUpload::class, fn ($j) => $j->runId === $runId)->first();
        $this->assertNotNull($job);
        $job->handle(app(JntCheckerRuns::class), app(\App\Services\Jnt\JntCheckerMatcher::class));
    }

    private function runQueuedUpdate(string $runId): void
    {
        $job = Queue::pushed(RunJntCheckerUpdate::class, fn ($j) => $j->runId === $runId)->last();
        $this->assertNotNull($job);
        $job->handle(app(JntCheckerRuns::class));
    }

    private function upload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    /** Bawat upload ay inililipat (move), kaya kopya ang ipinapasa. */
    private function copy(string $path): string
    {
        $dst = $this->tmp . DIRECTORY_SEPARATOR . uniqid('up_') . '.' . pathinfo($path, PATHINFO_EXTENSION);
        copy($path, $dst);

        return $dst;
    }

    private function xlsx(string $name, array $rows, array $header = self::HEADER): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $all = array_merge([$header], $rows);
        foreach ($all as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                // explicit string: ang phone na may leading 0 ay hindi gagawing number
                $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function zip(string $name, array $entries): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $file) $zip->addFile($file, $entry);
        $zip->close();

        return $path;
    }

    private function textFile(string $name, string $content): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }
}
