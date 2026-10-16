<?php

namespace Tests\Feature\SummaryOverall;

use PHPUnit\Framework\Attributes\DataProvider;

/** /summary/overall at ang data nito: CEO lang; ang patay na /daily route ay wala na (404). */
class CeoOnlyTest extends SummaryOverallTestCase
{
    public static function roles(): array
    {
        return [
            'CEO'             => ['CEO', 200],
            'CEO, padded'     => ['  ceo ', 200],
            'Marketing - OIC' => ['Marketing - OIC', 403],
            'Marketing'       => ['Marketing', 403],
            'staff'           => ['Encoder', 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_page_and_data_are_ceo_only(string $role, int $status): void
    {
        $this->seedFixture();
        $this->actingAs($this->user($role, 'u' . md5($role) . '@example.test'));

        $this->get('/summary/overall')->assertStatus($status);
        $this->getJson('/summary/overall/data?start_date=' . self::START . '&end_date=' . self::END)->assertStatus($status);
        $this->get('/summary/overall/daily')->assertNotFound();
    }
}
