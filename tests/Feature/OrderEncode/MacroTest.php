<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Macro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** STEP 6 ng auto-encode.gs at ang FILTER ng ENCODER, laban sa maliit na LocationData. */
class MacroTest extends TestCase
{
    private function macro(?array $rows = null): Macro
    {
        return new Macro($rows ?? Fixtures::macroRows());
    }

    public function test_the_cebu_cebu_example_takes_the_first_candidate_in_list_order(): void
    {
        $r = $this->macro()->run('CEBU', 'CEBU', '');

        $this->assertSame('cebu cebu', $r['p1']);
        $this->assertSame(['AGUSAN-DEL-SUR', 'AGUSAN-DEL-SUR-ESPERANZA', 'CEBULAN'], [$r['province'], $r['city'], $r['barangay']]);
        $this->assertSame(4, $r['candidates']);
        $this->assertSame([3, 'Barangay', '❌'], [$r['points'], $r['remark'], $r['icon']]);
    }

    public function test_a_full_unique_match_is_seven_points_and_a_check(): void
    {
        $r = $this->macro()->run('Metro-Manila', 'Quezon City', 'Bagumbayan');

        $this->assertSame([7, '', '✅', 1], [$r['points'], $r['remark'], $r['icon'], $r['candidates']]);
        $this->assertSame('QUEZON-CITY', $r['city']);
    }

    public function test_seven_points_with_more_than_one_candidate_is_multiple_suggested_address(): void
    {
        $rows = array_merge(Fixtures::macroRows(), [Fixtures::macroRows()[6]]);

        $r = $this->macro($rows)->run('ABRA', 'ABRA DOLORES', 'BAYAAN');

        $this->assertSame([7, 'MULTIPLE SUGGESTED ADDRESS', '', 2], [$r['points'], $r['remark'], $r['icon'], $r['candidates']]);
    }

    public function test_a_word_the_first_candidate_does_not_contain_is_dropped_from_the_query(): void
    {
        $r = $this->macro()->run('NARNIA', 'CEBU CITY', 'LAHUG');

        $this->assertSame('cebu city lahug', $r['p1']);
        $this->assertSame([6, 'Province'], [$r['points'], $r['remark']]);
        $this->assertSame('CEBU', $r['province']);
    }

    public static function points(): array
    {
        return [
            'province only' => [['ABRA', '', ''], 1, 'City and Barangay'],
            'city only' => [['', 'ABRA DOLORES', ''], 2, 'Province and Barangay'],
            'province and city' => [['ABRA', 'ABRA DOLORES', ''], 3, 'Barangay'],
            'barangay only' => [['', '', 'BAYAAN'], 4, 'Province and City'],
            'province and barangay' => [['ABRA', '', 'BAYAAN'], 5, 'City'],
            'city and barangay' => [['', 'ABRA DOLORES', 'BAYAAN'], 6, 'Province'],
            'nothing matches' => [['NARNIA', '', ''], 0, '❌'],
        ];
    }

    #[DataProvider('points')]
    public function test_points_one_two_four_and_their_remarks(array $in, int $points, string $remark): void
    {
        $r = $this->macro()->run(...$in);

        $this->assertSame([$points, $remark, '❌'], [$r['points'], $r['remark'], $r['icon']]);
    }

    public function test_no_match_gives_na_and_blank_input_writes_nothing(): void
    {
        $r = $this->macro()->run('NARNIA', '', '');
        $this->assertSame(['#N/A', '#N/A', '#N/A', 0], [$r['province'], $r['city'], $r['barangay'], $r['candidates']]);

        $this->assertNull($this->macro()->run('', ' ', ''));
    }

    public function test_only_the_first_six_words_of_the_query_count(): void
    {
        $this->assertSame(7, $this->macro()->filter('a a a a a a zzzz')['count']);
        $this->assertSame(0, $this->macro()->filter('a a a a a zzzz')['count']);
    }
}
