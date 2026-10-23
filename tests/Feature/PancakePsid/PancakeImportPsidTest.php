<?php

namespace Tests\Feature\PancakePsid;

use Illuminate\Support\Facades\DB;

/** Import ng Pancake export: ang Sender ID (PSID) ang susi ng conversation; walang Sender ID = gaya ng dati. */
class PancakeImportPsidTest extends PancakePsidTestCase
{
    private const PHONE = ' 09171234567';

    private function rows(): array
    {
        return DB::table('pancake_conversations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_export_with_sender_id_inserts_rows_keyed_by_psid_and_two_psids_with_one_name_are_two_rows(): void
    {
        $log = $this->import($this->xlsx([
            ['hi' . self::PHONE, 'Ana Cruz', '12345678901234567', '5001'],
            ['order ko', 'Ana Cruz', '12345678901234567', '5001'],
            ['hello' . self::PHONE, 'Ana Cruz', '76543210987654321', '5001'],
        ]));

        $rows = $this->rows();
        $this->assertSame([['12345678901234567', "hi 09171234567\norder ko"], ['76543210987654321', 'hello 09171234567']],
            array_map(fn ($r) => [$r['psid'], $r['customers_chat']], $rows));
        $this->assertSame([2, 0], [$log->inserted, $log->updated]);
    }

    public function test_same_psid_in_a_later_file_updates_the_row_and_takes_the_newest_name(): void
    {
        $this->import($this->xlsx([['hi' . self::PHONE, 'Ana Cruz', '12345678901234567', '5001']]));
        $log = $this->import($this->xlsx([['bagong chat' . self::PHONE, 'Ana C. Santos', '12345678901234567', '5001']]));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(['Ana C. Santos', 'bagong chat 09171234567'], [$rows[0]['full_name'], $rows[0]['customers_chat']]);
        $this->assertSame([0, 1], [$log->inserted, $log->updated]);
    }

    public function test_old_name_only_row_is_adopted_when_its_name_maps_to_one_psid_in_the_file(): void
    {
        $old = $this->conv('5001', 'Ana Cruz', 'lumang chat');
        $this->import($this->xlsx([['hi' . self::PHONE, 'Ana Cruz', '12345678901234567', '5001']]));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame([$old, '12345678901234567'], [(int) $rows[0]['id'], $rows[0]['psid']]);
    }

    public function test_old_name_only_row_is_not_adopted_when_the_name_has_two_psids_in_the_file(): void
    {
        $this->conv('5001', 'Ana Cruz', 'lumang chat');
        $this->import($this->xlsx([
            ['a' . self::PHONE, 'Ana Cruz', '12345678901234567', '5001'],
            ['b' . self::PHONE, 'Ana Cruz', '76543210987654321', '5001'],
        ]));

        $this->assertSame([null, '12345678901234567', '76543210987654321'], array_column($this->rows(), 'psid'));
    }

    public function test_adoption_sees_a_second_psid_even_when_it_is_after_a_4000_row_chunk(): void
    {
        $this->conv('5001', 'Ana Cruz', 'lumang chat');
        $rows = [['a' . self::PHONE, 'Ana Cruz', '12345678901234567', '5001']];
        for ($i = 0; $i < 4000; $i++) {
            $rows[] = ['x' . self::PHONE, 'Filler', '11111111111111111', '5001'];
        }
        $rows[] = ['b' . self::PHONE, 'Ana Cruz', '76543210987654321', '5001'];
        $this->import($this->xlsx($rows));

        $this->assertNull(DB::table('pancake_conversations')->where('full_name', 'Ana Cruz')->orderBy('id')->value('psid'));
    }

    public function test_file_without_sender_id_behaves_as_before_by_page_and_name(): void
    {
        $this->conv('5001', 'Ana Cruz', 'lumang chat');
        $log = $this->import($this->xlsx([
            ['bago' . self::PHONE, 'Ana Cruz', null, '5001'],
            ['iba' . self::PHONE, 'Ben Reyes', null, '5001'],
        ], withSenderId: false));

        $rows = $this->rows();
        $this->assertSame([['Ana Cruz', null, 'bago 09171234567'], ['Ben Reyes', null, 'iba 09171234567']],
            array_map(fn ($r) => [$r['full_name'], $r['psid'], $r['customers_chat']], $rows));
        $this->assertSame([1, 1], [$log->inserted, $log->updated]);
    }

    public function test_sender_id_stored_as_a_number_cell_keeps_all_17_digits(): void
    {
        $this->import($this->xlsx([['hi' . self::PHONE, 'Ana Cruz', 12345678901234567, '5001']]));

        $this->assertSame('12345678901234567', $this->rows()[0]['psid']);
    }

    public function test_bad_sender_ids_are_ignored_and_the_row_falls_back_to_name(): void
    {
        $this->import($this->xlsx([
            ['a' . self::PHONE, 'Ana Cruz', 'abc-not-an-id', '5001'],
            ['b' . self::PHONE, 'Ben Reyes', '12345', '5001'],           // masyadong maikli
            ['c' . self::PHONE, 'Cara Diaz', str_repeat('1', 26), '5001'], // masyadong mahaba
            ['d' . self::PHONE, 'Dan Lim', ' 1234-5678-9012-3456 ', '5001'], // digits lang ang tinatanggap
        ]));

        $this->assertSame([null, null, null, '1234567890123456'], array_column($this->rows(), 'psid'));
    }
}
