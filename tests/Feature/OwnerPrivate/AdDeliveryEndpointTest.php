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

    public function test_S_043_12_the_range_is_capped_at_92_days(): void
    {
        $user = $this->user();
        $this->actingAs($user)->getJson($this->url(['start_date' => '2026-07-16', 'end_date' => '2026-10-15', 'pages' => [self::PAGE]]))->assertOk();   // 92 araw, kasama ang dalawang dulo
        $this->actingAs($user)->getJson($this->url(['start_date' => '2026-07-15', 'end_date' => '2026-10-15', 'pages' => [self::PAGE]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanggang 92 araw lang ang range ng Delivered / RTS% sa expand.');
    }

    public function test_S_043_12_the_result_is_cached_until_the_owner_private_cache_version_changes(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered');
        // lumang version (ang bumpCacheVersion ay time() ng PHP, hindi gumagalaw sa travel)
        \Illuminate\Support\Facades\Cache::forever('owner_private:cache_version', '1');
        $user = $this->user();
        $get  = fn () => $this->actingAs($user)->getJson($this->url($this->valid()))->assertOk()->json('by_page.' . self::PAGE . '.page.shipped');
        $this->assertSame(1, $get());

        $this->order('WB2', 'AD1', 'Delivered');
        $this->assertSame(1, $get());   // galing sa cache

        \App\Http\Controllers\OwnerPrivateController::bumpCacheVersion();
        $this->assertSame(2, $get());
    }

    public function test_S_043_13_expand_all_posts_the_page_list(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered');
        $pages = array_merge([self::PAGE], array_map(fn ($i) => "Page number $i with a long name", range(1, 300)));

        $this->actingAs($this->user())
            ->postJson('/owner/private/ad-delivery', ['start_date' => self::START, 'end_date' => self::END, 'pages' => $pages])
            ->assertOk()
            ->assertJsonPath('by_page.' . self::PAGE . '.ads.AD1.delivered', 1);
        $this->actingAs($this->user('Data Encoder', 'enc2@example.test'))
            ->postJson('/owner/private/ad-delivery', ['start_date' => self::START, 'end_date' => self::END, 'pages' => [self::PAGE]])
            ->assertNotFound();
    }

    #[DataProvider('badQueries')]
    public function test_S_043_7_a_bad_query_is_422(array $query): void
    {
        $this->actingAs($this->user())->getJson($this->url($query))->assertStatus(422);
    }
}
