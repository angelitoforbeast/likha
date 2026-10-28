<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\JntCheckerController;
use App\Models\MacroOutput;
use App\Services\Jnt\JntPayloadBuilder;
use App\Services\MacroChecker;
use App\Services\OrderPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ang phone ng order sa website ay 9XXXXXXXXX (10 digits, gaya ng sheet): ang helper, ang command na nagko-convert ng mga
 * lumang 09XXXXXXXXX, ang Validate (format at same-day duplicate), at ang J&T na nagno-normalize pa rin sa 09… nang sarili.
 */
class PhoneTenDigitsTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Ang log ng command ay sa sariling folder ng test, hindi sa totoong storage/logs.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phone10-' . bin2hex(random_bytes(4));
        mkdir($this->storage . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        $this->app->useStoragePath($this->storage);

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('TIMESTAMP', 100)->nullable();
            $t->string('PAGE')->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('PHONE NUMBER', 100)->nullable();
            $t->string('STATUS')->nullable();
            $t->text('HISTORICAL LOGS')->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/logs/orders-phone-ten-digits.log');
        @rmdir($this->storage . '/logs');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function order(string $phone, string $day = '2026-10-28', array $over = []): MacroOutput
    {
        return MacroOutput::create($over + ['TIMESTAMP' => '10:15 ' . date('d-m-Y', strtotime($day)), 'PAGE' => 'Page Uno', 'FULL NAME' => 'Ana Cruz',
            'PHONE NUMBER' => $phone, 'ts_date' => $day]);
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function websiteForms(): array
    {
        return [
            '09 with spaces' => ['0917 123 4567', '9171234567'],
            '+63 with dashes' => ['+63 917-123-4567', '9171234567'],
            '639 form' => ['639171234567', '9171234567'],
            'already ten' => ['9171234567', '9171234567'],
            'empty' => ['', ''],
            'null' => [null, ''],
            'ten starting with 0' => ['0917123456', ''],
            'too long' => ['091712345678', ''],
        ];
    }

    #[DataProvider('websiteForms')]
    public function test_S_186_1_the_website_form_is_ten_digits_starting_with_9(?string $raw, string $expected): void
    {
        $this->assertSame($expected, OrderPhone::website($raw));
    }

    public function test_S_186_3_dry_run_counts_and_apply_converts_only_eleven_digit_09_rows_once(): void
    {
        $a = $this->order('09171234567', '2026-10-27');
        $b = $this->order('09181234567');
        $c = $this->order('09191234567');
        $keep = [
            $this->order('0917123456')->id => '0917123456',          // 10 digits na nagsisimula sa 0
            $this->order('091712345678')->id => '091712345678',      // 12 digits
            $this->order('09171234567890')->id => '09171234567890',  // 14 digits
            $this->order('')->id => '',
            $this->order('9171234567')->id => '9171234567',
            $this->order('0917-123456')->id => '0917-123456',        // 11 characters, hindi puro digit
        ];
        $touched = MacroOutput::query()->pluck('updated_at', 'id')->map(fn ($v) => (string) $v)->all();
        $this->travel(5)->minutes();

        $this->artisan('orders:phone-ten-digits')
            ->expectsOutputToContain('DRY RUN (walang binago; --apply para i-convert): 3 row na 09XXXXXXXXX')
            ->expectsTable(['ts_date', 'rows'], [['2026-10-27', 1], ['2026-10-28', 2]])
            ->expectsOutputToContain("#{$a->id}: 09171234567 → 9171234567 (2026-10-27)")
            ->assertSuccessful();
        $this->assertSame('09171234567', MacroOutput::find($a->id)->{'PHONE NUMBER'}, 'walang binago ang dry run');
        $this->assertFileDoesNotExist($this->storage . '/logs/orders-phone-ten-digits.log');

        $this->artisan('orders:phone-ten-digits --apply')->expectsOutputToContain('Changed: 3')->assertSuccessful();

        $phones = MacroOutput::query()->pluck('PHONE NUMBER', 'id')->map(fn ($v) => (string) $v)->all();
        $this->assertSame(['9171234567', '9181234567', '9191234567'], [$phones[$a->id], $phones[$b->id], $phones[$c->id]]);
        foreach ($keep as $id => $phone) $this->assertSame($phone, $phones[$id]);
        $this->assertSame($touched, MacroOutput::query()->pluck('updated_at', 'id')->map(fn ($v) => (string) $v)->all(), 'updated_at ay hindi ginagalaw');
        $this->assertNull(MacroOutput::find($a->id)->{'HISTORICAL LOGS'}, 'walang linya sa HISTORICAL LOGS');
        $log = file_get_contents($this->storage . '/logs/orders-phone-ten-digits.log');
        $this->assertStringContainsString("id={$a->id}\tbefore=09171234567\tafter=9171234567", $log);
        $this->assertSame(3, substr_count($log, "\n"));

        $this->artisan('orders:phone-ten-digits --apply')->expectsOutputToContain('Changed: 0')->assertSuccessful();
        $this->assertSame(3, substr_count(file_get_contents($this->storage . '/logs/orders-phone-ten-digits.log'), "\n"));
    }

    private function phoneReasons(MacroOutput $row, string $phone): array
    {
        $maps = ['provincesSet' => [], 'citiesByProv' => [], 'brgysByCityProv' => []];
        $gate = (new MacroChecker())->validateRow($row, ['FULL NAME' => 'Ana Cruz', 'PHONE NUMBER' => $phone, 'ADDRESS' => 'Blk 5',
            'PROVINCE' => '', 'CITY' => '', 'BARANGAY' => ''], $maps);

        return array_values(array_filter($gate['hard'], fn ($r) => str_starts_with($r, 'PHONE')));
    }

    public function test_S_186_4_validate_passes_a_converted_phone_and_flags_both_forms_as_same_day_duplicates(): void
    {
        $converted = $this->order('09171234567');
        $this->artisan('orders:phone-ten-digits --apply')->assertSuccessful();
        $converted->refresh();
        $this->assertSame([], $this->phoneReasons($converted, (string) $converted->{'PHONE NUMBER'}));

        $old = $this->order('09181234567');
        $new = $this->order('9181234567');
        $other = $this->order('09181234567', '2026-10-27');
        $reasons = $this->phoneReasons($new, '9181234567');
        $this->assertCount(1, $reasons);
        $this->assertStringStartsWith('PHONE duplicate sa parehong petsa: #' . $old->id . ' ', $reasons[0]);
        $this->assertStringNotContainsString('#' . $other->id . ' ', $reasons[0], 'ibang araw: hindi duplicate');
    }

    public function test_S_186_5_jnt_still_sends_and_matches_the_09_form(): void
    {
        $payload = JntPayloadBuilder::buildCreateFromMacroOutput(['id' => 1, 'phone_number' => '9171234567', 'full_name' => 'Ana Cruz'],
            ['sender_name' => 'Likha', 'eccompanyid' => 'X', 'customerid' => 'Y']);
        $this->assertSame(['09171234567', '09171234567'], [$payload['receiver']['phone'], $payload['receiver']['mobile']]);

        // Ang susi ng checker: ang order (9171234567) at ang waybill (receiver 09171234567) ay iisang susi.
        $key = (new \ReflectionMethod(JntCheckerController::class, 'makeKey'))->getClosure(new JntCheckerController());
        $this->assertSame($key('Page Uno', '09171234567', '2 x Glow Serum', '998'), $key('Page Uno', '9171234567', '2 x Glow Serum', '998'));
    }
}
