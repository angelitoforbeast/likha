<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class AdDeliveryEndpointTest extends AdDeliveryTestCase
{
    private function url(array $query): string
    {
        return '/owner/private/ad-delivery?' . http_build_query($query);
    }

    private function valid(): array
    {
        return ['start_date' => self::START, 'end_date' => self::END, 'pages' => [self::PAGE]];
    }

    /** @return array<string, array{0: string}> */
    public static function viewerRoles(): array
    {
        return ['CEO' => ['CEO'], 'Marketing - OIC' => ['Marketing - OIC'], 'Marketing' => ['marketing']];
    }

    #[DataProvider('viewerRoles')]
    public function test_S_043_7_roles_of_owner_private_get_the_counts(string $role): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered');
        $this->order('WB2', null, 'Returned');
        $before = DB::table('macro_output')->count() + DB::table('from_jnts')->count() + DB::table('ads_manager_reports')->count();

        $res = $this->actingAs($this->user($role))->getJson($this->url($this->valid()));

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('by_page.' . self::PAGE . '.ads.AD1.delivered', 1)
            ->assertJsonPath('by_page.' . self::PAGE . '.adsets.SET1.delivered', 1)
            ->assertJsonPath('by_page.' . self::PAGE . '.campaigns.CMP1.counts.delivered', 1)
            ->assertJsonPath('by_page.' . self::PAGE . '.no_ad.returned', 1)
            ->assertJsonPath('by_page.' . self::PAGE . '.page.shipped', 2);
        // read-only
        $this->assertSame($before, DB::table('macro_output')->count() + DB::table('from_jnts')->count() + DB::table('ads_manager_reports')->count());
    }

    public function test_S_043_7_other_roles_get_404(): void
    {
        $this->getJson($this->url($this->valid()))->assertStatus(401);   // guest: hinaharang ng auth bago pa ang controller
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->getJson($this->url($this->valid()))->assertNotFound();
        $this->actingAs($this->user('', 'none@example.test'))->getJson($this->url($this->valid()))->assertNotFound();
    }

    /** @return array<string, array{0: array}> */
    public static function badQueries(): array
    {
        return [
            'no start'          => [['end_date' => self::END, 'pages' => [self::PAGE]]],
            'bad end'           => [['start_date' => self::START, 'end_date' => '15-10-2026', 'pages' => [self::PAGE]]],
            'end before start'  => [['start_date' => self::END, 'end_date' => self::START, 'pages' => [self::PAGE]]],
            'no pages'          => [['start_date' => self::START, 'end_date' => self::END]],
            'pages not a list'  => [['start_date' => self::START, 'end_date' => self::END, 'pages' => self::PAGE]],
            'page is an array'  => [['start_date' => self::START, 'end_date' => self::END, 'pages' => [[self::PAGE]]]],
            'pages keyed'       => [['start_date' => self::START, 'end_date' => self::END, 'pages' => ['x' => self::PAGE]]],
            '501 pages'         => [['start_date' => self::START, 'end_date' => self::END, 'pages' => array_map(fn ($i) => "P$i", range(1, 501))]],
            'name over 255'     => [['start_date' => self::START, 'end_date' => self::END, 'pages' => [str_repeat('a', 256)]]],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_S_043_7_a_bad_query_is_422(array $query): void
    {
        $this->actingAs($this->user())->getJson($this->url($query))->assertStatus(422);
    }
}
