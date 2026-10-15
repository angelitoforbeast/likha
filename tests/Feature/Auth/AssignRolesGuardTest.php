<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang /assign-roles ay nagtatakda ng role ng kahit sinong user (pati CEO), kaya CEO lang na naka-login
 * ang puwedeng magbukas at gumamit nito. Ang sign-up page ay nananatiling bukas.
 */
class AssignRolesGuardTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });
        foreach (['CEO', 'Marketing', 'Data Encoder'] as $name) {
            DB::table('roles')->insert(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function target(): User
    {
        return $this->user('Data Encoder', 'target@example.test');
    }

    private function roleOf(User $user): ?string
    {
        return DB::table('employee_profiles')->where('user_id', $user->id)->value('role');
    }

    private function postRole(User $target, string $role)
    {
        return $this->postJson('/assign-roles/' . $target->id, ['user_id' => $target->id, 'role_name' => $role]);
    }

    public function test_signed_out_get_redirects_to_login(): void
    {
        $this->get('/assign-roles')->assertRedirect('/login');
    }

    public function test_signed_out_post_is_refused_and_changes_no_role(): void
    {
        $target = $this->target();

        $this->postRole($target, 'CEO')->assertUnauthorized();

        $this->assertSame('Data Encoder', $this->roleOf($target));
    }

    public function test_non_ceo_gets_403_on_both_and_changes_no_role(): void
    {
        $target = $this->target();
        $this->actingAs($this->user('Marketing'));

        $this->get('/assign-roles')->assertForbidden();
        $this->postRole($target, 'CEO')->assertForbidden()->assertJson(['success' => false]);

        $this->assertSame('Data Encoder', $this->roleOf($target));
    }

    public static function ceoSpellings(): array
    {
        return ['CEO' => ['CEO'], 'may space at maliit na titik' => ['  ceo ']];
    }

    #[DataProvider('ceoSpellings')]
    public function test_ceo_opens_the_page_and_sets_an_existing_role(string $ceoRole): void
    {
        $target = $this->target();
        $this->actingAs($this->user($ceoRole));

        $this->get('/assign-roles')->assertOk()->assertSee('target@example.test');
        $this->postRole($target, 'Marketing')->assertOk()->assertJson(['success' => true, 'role' => 'Marketing']);

        $this->assertSame('Marketing', $this->roleOf($target));
    }

    public function test_unknown_role_name_is_refused(): void
    {
        $target = $this->target();
        $this->actingAs($this->user('CEO'));

        $this->postRole($target, 'Super Admin')->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame('Data Encoder', $this->roleOf($target));
    }

    public function test_sign_up_page_stays_open_signed_out(): void
    {
        $this->get('/registerlogin')->assertOk();
    }
}
