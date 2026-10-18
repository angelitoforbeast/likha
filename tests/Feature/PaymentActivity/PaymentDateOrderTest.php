<?php

namespace Tests\Feature\PaymentActivity;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Jobs\ProcessPaymentActivityUpload;
use App\Models\UploadLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Meta payment import: isang date order bawat file, strict parse, at range guard. Pekeng data lang. */
class PaymentDateOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Schema::create('upload_logs', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->string('disk')->nullable();
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('status')->nullable();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('inserted')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('error_rows')->default(0);
            $t->text('error_message')->nullable();
            $t->string('errors_path')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2025_10_19_131712_create_payment_activity_ads_manager_table.php',
        ]]);
        // Production has a unique index on transaction_id (mysql/pgsql-only migration); the upsert needs it.
        DB::statement('CREATE UNIQUE INDEX pa_unique_txn ON payment_activity_ads_manager (transaction_id)');
    }

    /** Meta-style CSV. $billing ay buong linya, hal. "Billing Report: 10/1/2026 - 10/9/2026". */
    private function import(string $originalName, ?string $billing, array $rows, string $stamp = '20261012_100000'): UploadLog
    {
        $lines = [
            'Meta information',
            'Advertiser Information',
            'Account: 1000000000000001,Business: Test Shop',
            '',
        ];
        if ($billing !== null) $lines[] = $billing;
        $lines[] = '';
        $lines[] = 'Payment Method: Visa ···· 0000';
        $lines[] = 'Date,Transaction ID,Amount,Currency';
        foreach ($rows as [$date, $txn, $amount]) {
            $lines[] = "{$date},{$txn},{$amount},PHP";
        }

        $path = 'uploads/payment_activity/' . $stamp . '__' . $originalName;
        Storage::disk('local')->put($path, implode("\n", $lines) . "\n");

        $log = UploadLog::create(['type' => 'payment_activity', 'disk' => 'local', 'path' => $path, 'original_name' => $originalName, 'status' => 'queued']);
        (new ProcessPaymentActivityUpload($path, $originalName, 'batch-0001', 'tester', 'local', $log->id))->handle();

        return $log->fresh();
    }

    private function dateOf(string $txn): ?string
    {
        return DB::table('payment_activity_ads_manager')->where('transaction_id', $txn)->value('date');
    }

    public function test_S_063_1_a_number_above_12_in_any_row_or_the_billing_line_decides_the_order(): void
    {
        // Lowercase header says D/M, but a row has 16 in the second place: the numbers win → M/D.
        $this->import('pay.csv', 'Billing report: 10/1/2026 - 10/9/2026', [
            ['10/3/2026', 't-1a', '100.00'],
            ['10/16/2026', 't-1b', '200.00'],
        ], '20261017_100000');
        $this->assertSame('2026-10-03', $this->dateOf('t-1a'));
        $this->assertSame('2026-10-16', $this->dateOf('t-1b'));

        // Capital-R header says M/D, but the billing line has 25 first → D/M.
        $this->import('pay2.csv', 'Billing Report: 25/09/2026 - 04/10/2026', [
            ['03/10/2026', 't-1c', '300.00'],
        ]);
        $this->assertSame('2026-10-03', $this->dateOf('t-1c'));
    }

    public function test_S_063_2_with_all_numbers_12_or_less_the_filename_iso_range_decides(): void
    {
        // Lowercase header would say D/M; the filename range proves M/D.
        $this->import('2026-10-01--2026-10-09_Invoice_Summary.csv', 'Billing report: 10/1/2026 - 10/9/2026', [
            ['10/3/2026', 't-2a', '340669.22'],
        ]);
        $this->assertSame('2026-10-03', $this->dateOf('t-2a'));

        // Single-dash filename form; the range proves D/M.
        $this->import('2026-10-01-2026-10-09_Invoice_Summary.csv', 'Billing Report: 01/10/2026 - 09/10/2026', [
            ['04/10/2026', 't-2b', '50.00'],
        ]);
        $this->assertSame('2026-10-04', $this->dateOf('t-2b'));
    }

    public function test_S_063_3_without_numbers_or_filename_range_the_header_wording_decides(): void
    {
        $cases = [
            ['Billing Report: 10/1/2026 - 10/9/2026', '10/3/2026', 't-3a'],   // capital R → M/D
            ['Billing report: 01/10/2026 - 09/10/2026', '03/10/2026', 't-3b'], // lowercase r → D/M
        ];
        foreach ($cases as [$billing, $date, $txn]) {
            $this->import('payments.csv', $billing, [[$date, $txn, '10.00']]);
            $this->assertSame('2026-10-03', $this->dateOf($txn), $billing);
        }
    }

    public function test_S_063_4_an_undecidable_file_is_rejected_with_a_clear_error(): void
    {
        $cases = [
            'no billing line'     => [null, [['10/3/2026', 't-4a', '10.00']]],
            'contradicting rows'  => ['Billing Report: 10/1/2026 - 10/9/2026', [['10/13/2026', 't-4b', '10.00'], ['13/10/2026', 't-4c', '10.00']]],
        ];
        foreach ($cases as $label => [$billing, $rows]) {
            $log = $this->import('payments.csv', $billing, $rows, '20261020_100000');
            $this->assertSame('failed', $log->status, $label);
            $this->assertSame('Cannot tell the date order of this file', $log->error_message, $label);
        }
        $this->assertSame(0, DB::table('payment_activity_ads_manager')->count());
    }

    public function test_S_063_5_strict_parse_never_rolls_an_invalid_date_over(): void
    {
        // 5/31 decides M/D; 2/30 is not a real date and must be refused, not stored as March 2.
        $log = $this->import('payments.csv', 'Billing Report: 2/1/2026 - 6/1/2026', [
            ['5/31/2026', 't-5a', '10.00'],
            ['2/30/2026', 't-5b', '20.00'],
        ], '20260601_100000');
        $this->assertSame('2026-05-31', $this->dateOf('t-5a'));
        $this->assertNull($this->dateOf('t-5b'));
        $this->assertSame(1, (int) $log->error_rows);
    }

    public function test_S_063_6_rows_outside_the_filename_range_or_after_the_upload_date_are_refused(): void
    {
        $log = $this->import('2026-10-01--2026-10-09_Invoice_Summary.csv', 'Billing Report: 10/1/2026 - 10/9/2026', [
            ['10/3/2026', 't-6a', '10.00'],   // inside
            ['9/30/2026', 't-6b', '10.00'],   // one day before the range: slack
            ['9/28/2026', 't-6c', '10.00'],   // outside the range
            ['10/9/2026', 't-6d', '10.00'],   // inside the range but after the upload date (+1 day slack)
        ], '20261007_100000');

        $this->assertSame('2026-10-03', $this->dateOf('t-6a'));
        $this->assertSame('2026-09-30', $this->dateOf('t-6b'));
        $this->assertNull($this->dateOf('t-6c'));
        $this->assertNull($this->dateOf('t-6d'));
        $this->assertSame('done', $log->status);
        $this->assertSame(2, (int) $log->error_rows);
        $this->assertNotNull($log->error_message);
    }

    public function test_S_063_7_re_importing_an_ambiguous_capital_R_file_keeps_the_correct_date(): void
    {
        DB::table('payment_activity_ads_manager')->insert([
            'date' => '2026-10-03', 'transaction_id' => 't-7a', 'amount' => 340669.22, 'ad_account' => '1000000000000001',
            'payment_method' => 'Visa ···· 0000', 'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00',
        ]);

        $log = $this->import('2026-10-02--2026-10-10_Invoice_Summary.csv', 'Billing Report: 10/2/2026 - 10/10/2026', [
            ['10/3/2026', 't-7a', '340669.22'],
            ['10/4/2026', 't-7b', '1000.00'],
        ]);

        $this->assertSame('done', $log->status);
        $this->assertSame('2026-10-03', $this->dateOf('t-7a'));
        $this->assertSame('2026-10-04', $this->dateOf('t-7b'));
    }

    public function test_S_063_8_the_upload_page_shows_the_error_of_a_failed_file(): void
    {
        $this->withoutMiddleware(AllowedIpMiddleware::class);
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
        Schema::create('tasks', function (Blueprint $t) { // binibilang ng view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $this->import('payments.csv', null, [['10/3/2026', 't-8a', '10.00']]);
        $user = User::create(['name' => 'Test User', 'email' => 'user@example.test', 'password' => 'secret-password']);

        $this->actingAs($user)->get(route('ads_payment.upload'))
            ->assertOk()
            ->assertSee('Cannot tell the date order of this file');
    }
}
