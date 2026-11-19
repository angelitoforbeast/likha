<?php

namespace Tests\Feature\Orders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec 307 FU-5: `orders:placeholder-mixups` ay naglilista, nang walang isinusulat, ng mga row na "Facebook User" na ang
 * FULL NAME o PHONE NUMBER ay tila kinopya mula sa ibang customer ng parehong page at araw. Gawa-gawa ang mga customer;
 * ang mga id ay ang sa 18/11.
 */
class PlaceholderMixupsCommandTest extends TestCase
{
    private const PAGE = 'Elyse Abad';
    private const DAY = '2026-11-18';
    private const MERCEDITA = 'Mercedita Santos';
    private const HER_PHONE = '9170000001';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('TIMESTAMP')->nullable();
            $t->string('PAGE')->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('fb_name')->nullable();
            $t->string('PHONE NUMBER', 100)->nullable();
            $t->text('ADDRESS')->nullable();
            $t->text('all_user_input')->nullable();
            $t->text('CXD')->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });
    }

    private function row(int $id, array $over): void
    {
        DB::table('macro_output')->insert($over + [
            'id' => $id, 'PAGE' => self::PAGE, 'ts_date' => self::DAY, 'fb_name' => 'Facebook User', 'ADDRESS' => 'Purok 3, Lihim na Kalye',
        ]);
    }

    /** Ang block ni Astra sa CXD, na naglalaman ng kinopyang pangalan at phone (hindi ito sinulat ng customer). */
    private function astraBlock(string $name, string $phone): string
    {
        return "--- ASTRA 2026-11-18 21:30 ---\nName: {$name}\nPhone Number: {$phone}\nAddress: Purok 3, Lihim na Kalye\n---";
    }

    private function fixture(): void
    {
        // Ang sariling row ni Mercedita: nasa sarili niyang usapan at form ang pangalan at phone.
        $this->row(1997706, ['FULL NAME' => self::MERCEDITA, 'PHONE NUMBER' => self::HER_PHONE,
            'all_user_input' => "FB NAME: Facebook User\nName: Mercedita Santos\nPhone: 0917 000 0001", 'CXD' => "---\nName: Mercedita Santos\nPhone Number: 09170000001\n---"]);
        // Ang apat na maling row: ibang customer, pero ang pangalan at/o phone ni Mercedita ang nasa row.
        $this->row(1997748, ['FULL NAME' => self::MERCEDITA, 'PHONE NUMBER' => self::HER_PHONE,
            'all_user_input' => "FB NAME: Facebook User\nRosa Lim\n639913485181", 'CXD' => $this->astraBlock(self::MERCEDITA, self::HER_PHONE)]);
        $this->row(1998721, ['FULL NAME' => self::MERCEDITA, 'PHONE NUMBER' => '9181112222',
            'all_user_input' => "FB NAME: Facebook User\nTess Go 09181112222", 'CXD' => $this->astraBlock(self::MERCEDITA, '9181112222')]);
        $this->row(1999317, ['FULL NAME' => 'Carlo Diaz', 'PHONE NUMBER' => self::HER_PHONE,
            'all_user_input' => "FB NAME: Facebook User\ncarlo  diaz\nnum ko 09191234567"]);
        $this->row(1999592, ['FULL NAME' => self::MERCEDITA, 'PHONE NUMBER' => '0' . self::HER_PHONE, 'all_user_input' => "FB NAME: Facebook User\nNena Cruz"]);

        // Hindi dapat lumabas: iisang customer na dalawang beses umorder (nasa sariling usapan ang pangalan at phone).
        $this->row(1999600, ['FULL NAME' => 'Liza Tan', 'PHONE NUMBER' => '9201112222', 'all_user_input' => "Liza Tan 09201112222"]);
        $this->row(1999601, ['FULL NAME' => 'Liza Tan', 'PHONE NUMBER' => '9201112222', 'all_user_input' => "liza tan\n0920 111 2222"]);
        // Ibang araw at ibang page: hindi tinitingnan laban kay Mercedita.
        $this->row(1999602, ['FULL NAME' => self::MERCEDITA, 'ts_date' => '2026-11-17', 'all_user_input' => 'iba']);
        $this->row(1999603, ['FULL NAME' => self::MERCEDITA, 'PAGE' => 'Ibang Page', 'all_user_input' => 'iba']);
        // Tunay na pangalan (hindi placeholder): hindi sinusuri, kahit kapareho ng iba.
        $this->row(1999604, ['fb_name' => 'Ana Cruz', 'FULL NAME' => self::MERCEDITA, 'all_user_input' => 'Ana Cruz']);
        // Ang ikalimang customer, sa ShadeShift Eyewear.
        $this->row(1999700, ['PAGE' => 'ShadeShift Eyewear', 'FULL NAME' => 'Joy Ramos', 'PHONE NUMBER' => '9223334444', 'all_user_input' => 'Joy Ramos 09223334444']);
        $this->row(1999701, ['PAGE' => 'ShadeShift Eyewear', 'fb_name' => 'facebook user', 'FULL NAME' => 'Joy Ramos', 'PHONE NUMBER' => '9223334444', 'all_user_input' => 'Mila Reyes']);
    }

    /** @return array{0:int, 1:string} */
    private function mixups(array $args): array
    {
        $code = Artisan::call('orders:placeholder-mixups', $args);

        return [$code, Artisan::output()];
    }

    /** @return array<int, array{0:string, 1:string, 2:string, 3:string, 4:string}> [id, page, day, field, from, has it] ayon sa id */
    private function tableRows(string $out): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $out) as $line) {
            if (!preg_match('/^\|\s*(\d+)\s*\|/', $line)) continue;
            $cells = array_map('trim', explode('|', trim($line, " |")));
            $rows[] = $cells;
        }

        return $rows;
    }

    public function test_FU_5_the_18_11_fixture_lists_the_four_wrong_rows_and_not_merceditas_own_row(): void
    {
        $this->fixture();
        $before = DB::table('macro_output')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        DB::enableQueryLog();

        [$code, $out] = $this->mixups(['--from' => self::DAY, '--to' => self::DAY, '--page' => self::PAGE]);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame(0, $code, $out);
        $this->assertSame([
            ['1997748', self::PAGE, self::DAY, 'FULL NAME', '1997706', 'yes'],
            ['1997748', self::PAGE, self::DAY, 'PHONE NUMBER', '1997706', 'yes'],
            ['1998721', self::PAGE, self::DAY, 'FULL NAME', '1997706', 'yes'],
            ['1999317', self::PAGE, self::DAY, 'PHONE NUMBER', '1997706', 'yes'],
            ['1999592', self::PAGE, self::DAY, 'FULL NAME', '1997706', 'yes'],
            ['1999592', self::PAGE, self::DAY, 'PHONE NUMBER', '1997706', 'yes'],
        ], $this->tableRows($out));
        $this->assertStringContainsString('Rows reported: 4', $out);
        $this->assertStringContainsString('Ids: 1997748,1998721,1999317,1999592', $out);
        $this->assertStringNotContainsString('1997706,', $out);

        // Bilang at id lang: walang pangalan, phone o address.
        foreach (['Mercedita', 'Santos', '917000', 'Purok', 'Lihim', 'Rosa', 'Carlo'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $out);
        }

        // Walang isinusulat.
        $this->assertSame($before, DB::table('macro_output')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertEmpty($queries->reject(fn ($q) => stripos(ltrim($q), 'select') === 0)->all(), 'SELECT lang');
    }

    public function test_FU_5_without_a_page_every_page_is_checked_and_the_other_day_is_outside_the_range(): void
    {
        $this->fixture();

        [$code, $out] = $this->mixups(['--from' => self::DAY, '--to' => self::DAY]);

        $this->assertSame(0, $code);
        $ids = array_values(array_unique(array_column($this->tableRows($out), 0)));
        $this->assertSame(['1997748', '1998721', '1999317', '1999592', '1999701'], $ids);
        $this->assertStringContainsString('Ids: 1997748,1998721,1999317,1999592,1999701', $out);
    }

    public function test_FU_5_bad_or_missing_dates_are_refused(): void
    {
        $this->assertSame(1, $this->mixups(['--from' => '18-11-2026', '--to' => self::DAY])[0]);
        $this->assertSame(1, $this->mixups(['--from' => self::DAY])[0]);
        $this->assertSame(1, $this->mixups(['--from' => '2026-11-19', '--to' => self::DAY])[0]);
    }

    public function test_FU_5_nothing_to_report_says_so(): void
    {
        $this->row(1, ['FULL NAME' => 'Liza Tan', 'PHONE NUMBER' => '9201112222', 'all_user_input' => 'Liza Tan 09201112222']);

        [$code, $out] = $this->mixups(['--from' => self::DAY, '--to' => self::DAY]);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->tableRows($out));
        $this->assertStringContainsString('Rows reported: 0', $out);
    }
}
