<?php

namespace Tests\Feature\SummaryOverall;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pareho ang numero ng /summary/overall/data bago at pagkatapos ng speed work.
 * Ang fixtures/*.json = output ng lumang query path sa parehong fixture (kinuha bago binago).
 */
class SameNumbersTest extends SummaryOverallTestCase
{
    /** Bilang ng statements ng lumang code kada /data call sa fixture na ito (sqlite). */
    private const OLD_STATEMENTS = 42;

    public static function modes(): array
    {
        return [
            'all pages'           => ['all', 'all'],
            'single page'         => ['Page Alpha', 'single'],
            'single page, padded' => ['page beta', 'beta'],
        ];
    }

    #[DataProvider('modes')]
    public function test_data_json_equals_the_old_output_field_by_field(string $page, string $fixture): void
    {
        $this->seedFixture();
        [$json] = $this->fetchData(['start_date' => self::START, 'end_date' => self::END, 'page_name' => $page]);

        $old = json_decode(file_get_contents(__DIR__ . "/fixtures/$fixture.json"), true);
        $this->assertSame(array_keys($old), array_keys($json));
        foreach ($old['ads_daily'] as $i => $row) {
            foreach ($row as $field => $value) {
                $this->assertArrayHasKey($field, $json['ads_daily'][$i], "row $i");
                $this->assertEqualsWithDelta($value, $json['ads_daily'][$i][$field], 1e-6, "row $i field $field");
            }
        }
        $this->assertCount(count($old['ads_daily']), $json['ads_daily']);
        foreach (['actual_rts_pct', 'top_summary', 'target_cpp', 'breakeven_cpp'] as $field) {
            $this->assertEqualsWithDelta($old[$field], $json[$field], 1e-6, $field);
        }
    }

    #[DataProvider('modes')]
    public function test_data_runs_fewer_statements_than_before(string $page): void
    {
        $this->seedFixture();
        [, $count] = $this->fetchData(['start_date' => self::START, 'end_date' => self::END, 'page_name' => $page]);

        $this->assertLessThanOrEqual(self::OLD_STATEMENTS / 2, $count);
    }

    public function test_page_list_is_the_trimmed_distinct_ads_pages(): void
    {
        $this->seedFixture();
        $this->actingAs($this->user());

        $this->get('/summary/overall')->assertOk()
            ->assertViewHas('pages', ['Page Alpha', 'Page Beta', 'Page Gamma']);
    }

    public function test_data_without_dates_uses_the_last_30_days(): void
    {
        $this->seedFixture();
        $this->travelTo(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Asia/Manila')));
        [$json] = $this->fetchData(['page_name' => 'all']);

        // Sept 21 – Oct 20: kasama ang Oct 1-2, hindi ang Sept 20.
        $total = end($json['ads_daily']);
        $this->assertSame(1650.5, (float) $total['adspent']);
        $this->assertSame(15, $total['orders']);
    }
}
