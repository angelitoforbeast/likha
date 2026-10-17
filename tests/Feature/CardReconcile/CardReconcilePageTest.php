<?php

namespace Tests\Feature\CardReconcile;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\CardStatement;
use App\Models\CardStatementLine;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** /ads_manager/payment/reconcile: CEO lang; upload → preview → itugma → statement view. Sqlite, walang totoong HTTP. */
class CardReconcilePageTest extends TestCase
{
    private const CARD = 'Visa ···· 5003';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Http::preventStrayRequests();
        Process::fake(['*' => Process::result('', 'pdftotext: not found', 127)]);

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
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2025_10_19_131712_create_payment_activity_ads_manager_table.php',
            'database/migrations/2026_10_18_400000_create_card_statements_tables.php',
        ]]);
    }

    private function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    private function meta(string $date, string $amount, string $card = self::CARD, ?string $txn = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('payment_activity_ads_manager')->insertGetId([
            'date' => $date, 'amount' => $amount, 'payment_method' => $card, 'ad_account' => 'act-' . $n,
            'transaction_id' => $txn ?? 'txn-' . $n, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Maliit na text PDF, isang linya bawat string. */
    private function pdf(array $lines): UploadedFile
    {
        $ops = "BT /F1 10 Tf 40 800 Td 14 TL\n";
        foreach ($lines as $l) $ops .= '(' . addcslashes($l, '()\\') . ") Tj T*\n";
        $ops .= 'ET';
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($ops) . " >>\nstream\n{$ops}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) { $offsets[] = strlen($out); $out .= ($i + 1) . " 0 obj\n{$o}\nendobj\n"; }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return UploadedFile::fake()->createWithContent('statement_4111111111115003.pdf', $out);
    }

    private const RCBC = [
        'RCBC BANKARD',
        'SALE DATE POST DATE DESCRIPTION AMOUNT',
        'PREVIOUS STATEMENT BALANCE 12,345.67',
        '09/09/26 09/10/26 FACEBK *AB12CD34EF DUBLIN IR 6,278.17',
        '09/09/26 09/10/26 FACEBK *GH56IJ78KL DUBLIN IR 100.00',
        '09/09/26 09/10/26 FACEBK *ST34UV56WX DUBLIN IR 100.00',
        '09/12/26 09/12/26 CASH PAYMENT 5,000.00-',
        '09/14/26 09/15/26 FACEBK *MN90OP12QR DUBLIN IR 432.10',
    ];

    private function upload(array $extra = [], ?UploadedFile $file = null)
    {
        return $this->post('/ads_manager/payment/reconcile', $extra + ['statement' => $file ?? $this->pdf(self::RCBC), 'bank' => 'auto', 'card_label' => self::CARD]);
    }

    public function test_S_049_13_only_the_ceo_reaches_the_pages_even_with_nav_settings_checked(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php']);
        $link = DB::table('nav_links')->insertGetId([
            'key' => 'card_reconcile_test', 'label' => 'Card check', 'route_url' => '/ads_manager/payment/reconcile', 'is_active' => true,
            'sort_order' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['Marketing', 'Marketing - OIC', 'Data Encoder'] as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $link, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->meta('2026-09-09', '6278.17');
        $this->actingAs($this->user());
        $this->upload()->assertSessionHasNoErrors()->assertRedirect();
        $id = CardStatement::sole()->id;

        foreach (['Marketing', 'Marketing - OIC', 'Data Encoder'] as $i => $role) {
            $this->actingAs($this->user($role, "u{$i}@example.test"));
            $this->get('/ads_manager/payment/reconcile')->assertNotFound();
            $this->get("/ads_manager/payment/reconcile/{$id}")->assertNotFound();
            $this->upload()->assertNotFound();
            $this->post("/ads_manager/payment/reconcile/{$id}/match", ['card_label' => self::CARD])->assertNotFound();
        }
        $this->assertSame(1, CardStatement::count());

        $this->actingAs($this->user('ceo', 'ceo2@example.test'));
        $this->get('/ads_manager/payment/reconcile')->assertOk();
        $this->get("/ads_manager/payment/reconcile/{$id}")->assertOk();
    }

    public function test_S_049_14_the_file_is_not_kept_only_the_parsed_lines(): void
    {
        foreach (['local', 'public', 's3', (string) config('filesystems.default')] as $disk) Storage::fake($disk);
        $this->meta('2026-09-09', '6278.17');
        $this->actingAs($this->user());

        $this->upload()->assertSessionHasNoErrors()->assertRedirect();

        foreach (['local', 'public', 's3', (string) config('filesystems.default')] as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(), $disk);
        }
        $s = CardStatement::sole();
        $this->assertSame(['rcbc', 'pdf', 'preview', 'statement_···· 5003.pdf', '2026-09-09', '2026-09-14'], [
            $s->bank, $s->source_kind, $s->status, $s->original_name, $s->period_start->toDateString(), $s->period_end->toDateString(),
        ]);
        $this->assertSame(6, CardStatementLine::count());
        $this->assertStringNotContainsString('4111111111115003', json_encode(DB::table('card_statements')->get()) . json_encode(DB::table('card_statement_lines')->get()));
    }

    public function test_S_049_3_a_pdf_is_read_with_pdftotext_when_it_works_and_a_scan_without_text_is_refused(): void
    {
        $this->meta('2026-09-20', '55.00');
        Process::fake(['*' => Process::result("RCBC\n09/20/26 09/21/26 FACEBK *ZZ11 DUBLIN IR 55.00\n")]);
        $this->actingAs($this->user());

        $this->upload()->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['2026-09-20', '55.00'], [CardStatementLine::sole()->sale_date->toDateString(), CardStatementLine::sole()->amount]);
        Process::assertRan(fn ($p) => in_array('-layout', $p->command, true));

        Process::fake(['*' => Process::result('', '', 1)]);
        $this->upload([], $this->pdf(['scanned page, no lines']))->assertSessionHasErrors('statement');
        $this->assertSame(1, CardStatement::count());
    }

    public function test_S_049_4_a_screenshot_upload_is_read_by_the_vision_model_and_a_bad_read_saves_nothing(): void
    {
        config(['services.openai.key' => 'sk-test-fake']);
        $this->meta('2026-09-09', '6278.17');
        $png = UploadedFile::fake()->image('shot.png', 20, 20);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'sorry, I cannot read this']]]])
            ->push(['choices' => [['message' => ['content' => json_encode(['lines' => [
                ['sale_date' => '2026-09-09', 'post_date' => '2026-09-10', 'description' => 'FACEBK *AB12 4111 1111 1111 5003', 'amount' => 6278.17, 'is_credit' => false],
                ['sale_date' => '2026-09-12', 'post_date' => '2026-09-12', 'description' => 'CASH PAYMENT', 'amount' => 5000, 'is_credit' => true],
            ]])]]]])]);
        $this->actingAs($this->user());

        $this->upload([], $png)->assertSessionHasErrors('statement');
        $this->assertSame(0, CardStatement::count());

        $this->upload([], UploadedFile::fake()->image('shot.png', 20, 20))->assertSessionHasNoErrors()->assertRedirect();
        $s = CardStatement::sole();
        $this->assertSame(['image', 'image'], [$s->source_kind, $s->bank]);
        $this->assertSame([['FACEBK *AB12 ···· 5003', true], ['CASH PAYMENT', false]], CardStatementLine::orderBy('line_no')->get()->map(fn ($l) => [$l->description, $l->is_ads])->all());
    }

    public function test_S_049_15_line_edits_are_saved_and_used_by_the_match(): void
    {
        $this->meta('2026-09-09', '6278.17');
        $fixed = $this->meta('2026-09-15', '423.10'); // nabasa bilang 432.10 noong 09-14
        $this->actingAs($this->user());
        $this->upload()->assertSessionHasNoErrors()->assertRedirect();
        $s = CardStatement::sole();
        $line = CardStatementLine::where('amount', '432.10')->sole();

        $other = CardStatement::create(['bank' => 'rcbc', 'source_kind' => 'pdf']);
        $foreign = CardStatementLine::create(['card_statement_id' => $other->id, 'line_no' => 1, 'description' => 'FACEBK X', 'amount' => '1.00', 'is_ads' => true]);

        $this->post("/ads_manager/payment/reconcile/{$s->id}/match", ['card_label' => 'Visa ···· 9999'])->assertSessionHasErrors('card_label');
        $this->post("/ads_manager/payment/reconcile/{$s->id}/match", ['card_label' => self::CARD, 'lines' => [$line->id => ['amount' => 'abc']]])->assertSessionHasErrors();

        $this->post("/ads_manager/payment/reconcile/{$s->id}/match", ['card_label' => self::CARD, 'lines' => [
            $line->id    => ['sale_date' => '2026-09-15', 'post_date' => '2026-09-15', 'amount' => '423.10', 'is_credit' => '0', 'is_ads' => '1'],
            $foreign->id => ['sale_date' => '2026-09-15', 'amount' => '999.00', 'is_credit' => '0', 'is_ads' => '1'],
        ]])->assertRedirect("/ads_manager/payment/reconcile/{$s->id}");

        $line->refresh();
        $this->assertSame(['2026-09-15', '423.10', 'sale_date', $fixed], [$line->sale_date->toDateString(), $line->amount, $line->match_kind, (int) $line->payment_activity_id]);
        $this->assertSame('1.00', $foreign->fresh()->amount, 'linya ng ibang statement ay hindi ginagalaw');
        $s->refresh();
        $this->assertSame(['matched', self::CARD, '2026-09-15'], [$s->status, $s->card_label, $s->period_end->toDateString()]);
    }

    public function test_S_049_16_the_list_the_statement_sheet_and_the_suriin_box(): void
    {
        $m1 = $this->meta('2026-09-09', '6278.17', self::CARD, 'txn-alpha-01');
        $this->meta('2026-09-09', '100.00');
        $this->meta('2026-09-09', '100.00');
        $this->meta('2026-09-11', '765.43', self::CARD, 'txn-gamma-03');           // wala sa card
        $this->meta('2026-09-11', '432.10', 'Mastercard ···· 7777');               // ibang card
        $this->meta(now('Asia/Manila')->addYears(2)->toDateString(), '55.55', self::CARD, 'txn-future-09'); // suriin
        $this->actingAs($this->user());
        $this->upload(['card_label' => ''])->assertSessionHasNoErrors()->assertRedirect();
        $s = CardStatement::sole();

        $preview = $this->get("/ads_manager/payment/reconcile/{$s->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Mungkahing card', $preview);
        $this->assertMatchesRegularExpression('/<option value="Visa ···· 5003"[^>]*selected/u', $preview, 'walang piniling card → ang mungkahi');

        $this->post("/ads_manager/payment/reconcile/{$s->id}/match", ['card_label' => self::CARD])->assertSessionHasNoErrors()->assertRedirect();

        $html = $this->get("/ads_manager/payment/reconcile/{$s->id}")->assertOk()->getContent();
        foreach (['Tugma 3', '₱6,478.17', 'Wala sa card 1', '₱765.43', 'Wala sa Meta 1', '₱432.10', '100-groups: 1 tally · 0 hindi', 'txn-alpha-01', 'act-' , 'txn-gamma-03', 'Not ads', 'data-status="none"', 'data-status="matched"'] as $want) {
            $this->assertStringContainsString($want, $html, $want);
        }
        $this->assertStringContainsString('2026-09-11', $html, 'per-day: araw na Meta lang');

        $list = $this->get('/ads_manager/payment/reconcile')->assertOk()->getContent();
        foreach ([self::CARD, '2026-09-09 – 2026-09-14', '75%', '-333.33', 'txn-future-09', 'Suriin'] as $want) {
            $this->assertStringContainsString($want, $list, $want);
        }
        $this->assertSame((int) $m1, (int) CardStatementLine::where('amount', '6278.17')->value('payment_activity_id'));
    }
}
