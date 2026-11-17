<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** AC-1: /roles/index, its store and its update answer 403 for anyone but the CEO. */
class RolesCeoOnlyTest extends AccessTestCase
{
    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleId = DB::table('roles')->insertGetId(['name' => 'Packer', 'guard_name' => 'web', 'access_level' => 1]);
    }

    public function test_the_ceo_opens_adds_and_renames_roles_as_before(): void
    {
        $ceo = $this->staff('CEO');

        $this->actingAs($ceo)->get('/roles/index')->assertOk()->assertSee('Packer');

        $this->actingAs($ceo)->post('/roles/store', ['name' => 'Rider', 'access_level' => 2])->assertRedirect();
        $this->assertTrue(DB::table('roles')->where('name', 'Rider')->exists());

        $this->actingAs($ceo)->post("/roles/update/{$this->roleId}", ['name' => 'Senior Packer', 'access_level' => 3])->assertRedirect();
        $this->assertSame('Senior Packer', DB::table('roles')->where('id', $this->roleId)->value('name'));
    }

    public function test_a_ceo_role_with_spaces_or_lower_case_is_still_the_ceo(): void
    {
        $this->actingAs($this->staff(' ceo '))->get('/roles/index')->assertOk();
    }

    public static function others(): array
    {
        $cases = [];
        foreach (['oic' => 'Marketing - OIC', 'staff' => 'Data Encoder', 'no role' => null, 'no profile' => false] as $who => $role) {
            foreach (['index', 'store', 'update'] as $route) {
                $cases["$route as $who"] = [$role, $route];
            }
        }

        return $cases;
    }

    #[DataProvider('others')]
    public function test_anyone_else_gets_403_and_nothing_changes(string|null|false $role, string $route): void
    {
        $user = $this->staff($role);
        $before = DB::table('roles')->orderBy('id')->get()->toArray();

        $response = match ($route) {
            'index'  => $this->actingAs($user)->get('/roles/index'),
            'store'  => $this->actingAs($user)->post('/roles/store', ['name' => 'Rider']),
            'update' => $this->actingAs($user)->post("/roles/update/{$this->roleId}", ['name' => 'Boss']),
        };

        $response->assertForbidden();
        $this->assertEquals($before, DB::table('roles')->orderBy('id')->get()->toArray());
    }
}
