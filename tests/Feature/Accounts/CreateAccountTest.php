<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use App\Support\AccountRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AR-1 and AR-2: a CEO or a Marketing - OIC creates an account at /accounts with its role; only a CEO gives CEO;
 * everyone else, and a guest, can neither open nor post the page.
 */
class CreateAccountTest extends AccountsTestCase
{
    // ── AR-1 ──────────────────────────────────────────────────────────────

    public function test_AR1_ceo_creates_a_marketing_account_that_logs_in(): void
    {
        $ceo = $this->user('CEO', 'Rosa Villanueva');

        $response = $this->actingAs($ceo)->post('/accounts', $this->form());

        $response->assertRedirect('/accounts/create');
        $response->assertSessionHasNoErrors();

        $user = User::where('email', 'nina@example.test')->firstOrFail();
        $this->assertSame('Nina Santos', $user->name);
        $this->assertTrue(Hash::check('nina-pass-1', $user->password));

        $profiles = DB::table('employee_profiles')->where('user_id', $user->id)->get();
        $this->assertCount(1, $profiles, 'exactly one employee profile');
        $this->assertSame('Marketing', $profiles[0]->role);
        $this->assertSame('Nina Santos', $profiles[0]->name);
        $this->assertSame('Active', $profiles[0]->status);
        $this->assertSame('EMP-' . str_pad((string) $user->id, 3, '0', STR_PAD_LEFT), $profiles[0]->employee_code);

        // The page shows the new account with a success message.
        $html = $this->actingAs($ceo)->get('/accounts/create')->assertOk()->getContent();
        $this->assertStringContainsString('Account created.', $html);
        $this->assertStringContainsString('nina@example.test', $html);
        $this->assertStringContainsString($profiles[0]->employee_code, $html);

        // The new account signs in at /login.
        $this->post('/logout');
        $this->assertGuest();
        $this->post('/login', ['email' => 'nina@example.test', 'password' => 'nina-pass-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_AR1_marketing_oic_creates_a_data_encoder_oic_account(): void
    {
        $oic = $this->user('Marketing - OIC');

        $this->actingAs($oic)->post('/accounts', $this->form(['role' => 'Data Encoder - OIC']))
            ->assertRedirect('/accounts/create')->assertSessionHasNoErrors();

        $id = User::where('email', 'nina@example.test')->value('id');
        $this->assertSame('Data Encoder - OIC', DB::table('employee_profiles')->where('user_id', $id)->value('role'));
    }

    public static function invalidForms(): array
    {
        return [
            'bad email'             => [['email' => 'not-an-email'], 'email'],
            'empty email'           => [['email' => ''], 'email'],
            'email already used'    => [['email' => 'taken@example.test'], 'email'],
            'password too short'    => [['password' => '12345', 'password_confirmation' => '12345'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'something-else'], 'password'],
            'empty password'        => [['password' => '', 'password_confirmation' => ''], 'password'],
            'empty name'            => [['name' => ''], 'name'],
            'blank name'            => [['name' => '   '], 'name'],
            'role not in the list'  => [['role' => 'Data Encoder - Head'], 'role'],
            'made-up role'          => [['role' => 'Super Admin'], 'role'],
            'empty role'            => [['role' => ''], 'role'],
            'role as an array'      => [['role' => ['CEO']], 'role'],
        ];
    }

    #[DataProvider('invalidForms')]
    public function test_AR1_invalid_input_shows_the_error_and_creates_nothing(array $overrides, string $field): void
    {
        $ceo = $this->user('CEO');
        $this->user('Marketing', 'Taken', 'taken@example.test');
        $users = $this->userCount();
        $profiles = $this->profileCount();

        $this->actingAs($ceo)->from('/accounts/create')->post('/accounts', $this->form($overrides))
            ->assertRedirect('/accounts/create')
            ->assertSessionHasErrors($field);

        $this->assertSame($users, $this->userCount());
        $this->assertSame($profiles, $this->profileCount());
    }

    public function test_AR1_the_form_shows_the_errors(): void
    {
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->from('/accounts/create')->post('/accounts', $this->form(['email' => 'not-an-email', 'name' => '']));
        $html = $this->actingAs($ceo)->get('/accounts/create')->assertOk()->getContent();

        $this->assertStringContainsString('The email field must be a valid email address.', $html);
        $this->assertStringContainsString('The name field is required.', $html);
        // Old input comes back, but never a password.
        $this->assertStringContainsString('value="not-an-email"', $html);
        $this->assertStringNotContainsString('nina-pass-1', $html);
    }

    // ── AR-2 ──────────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_AR2_ceo_creates_each_role(string $role): void
    {
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->post('/accounts', $this->form(['role' => $role]))->assertSessionHasNoErrors();

        $id = User::where('email', 'nina@example.test')->value('id');
        $this->assertSame($role, DB::table('employee_profiles')->where('user_id', $id)->value('role'));
    }

    public static function everyRole(): array
    {
        return array_combine(AccountRoles::ALL, array_map(fn ($r) => [$r], AccountRoles::ALL));
    }

    public static function rolesBelowCeo(): array
    {
        return ['Marketing - OIC' => ['Marketing - OIC'], 'Data Encoder - OIC' => ['Data Encoder - OIC'], 'Marketing' => ['Marketing'], 'Data Encoder' => ['Data Encoder']];
    }

    #[DataProvider('rolesBelowCeo')]
    public function test_AR2_marketing_oic_creates_every_role_but_ceo(string $role): void
    {
        $oic = $this->user('Marketing - OIC');

        $this->actingAs($oic)->post('/accounts', $this->form(['role' => $role]))->assertSessionHasNoErrors();

        $id = User::where('email', 'nina@example.test')->value('id');
        $this->assertSame($role, DB::table('employee_profiles')->where('user_id', $id)->value('role'));
    }

    public static function ceoSpellings(): array
    {
        return ['CEO' => ['CEO'], 'lower case' => ['ceo'], 'spaces around' => ['  Ceo ']];
    }

    #[DataProvider('ceoSpellings')]
    public function test_AR2_marketing_oic_posting_ceo_is_refused_and_creates_nothing(string $posted): void
    {
        $oic = $this->user('Marketing - OIC');
        $users = $this->userCount();

        $this->actingAs($oic)->from('/accounts/create')->post('/accounts', $this->form(['role' => $posted]))
            ->assertRedirect('/accounts/create')
            ->assertSessionHasErrors('role');

        $this->assertSame($users, $this->userCount());
        $this->assertNull(User::where('email', 'nina@example.test')->first());
    }

    public static function refusedRoles(): array
    {
        return [
            'Marketing'          => ['Marketing'],
            'Data Encoder - OIC' => ['Data Encoder - OIC'],
            'Data Encoder'       => ['Data Encoder'],
            'no role'            => [null],
            'empty role'         => [''],
        ];
    }

    #[DataProvider('refusedRoles')]
    public function test_AR2_other_roles_cannot_open_or_post(?string $role): void
    {
        $actor = $this->user($role);
        $users = $this->userCount();

        $this->actingAs($actor)->get('/accounts/create')->assertForbidden();
        $this->actingAs($actor)->post('/accounts', $this->form(['role' => 'Marketing']))->assertForbidden();

        $this->assertSame($users, $this->userCount());
    }

    public function test_AR2_a_guest_is_sent_to_login_and_creates_nothing(): void
    {
        $this->get('/accounts/create')->assertRedirect('/login');
        $this->post('/accounts', $this->form())->assertRedirect('/login');

        $this->assertSame(0, $this->userCount());
    }

    public static function actorSpellings(): array
    {
        return [
            'CEO, lower case'                  => ['ceo', AccountRoles::ALL],
            'CEO, spaces around'               => ['  CEO  ', AccountRoles::ALL],
            'Marketing - OIC, lower case'      => ['marketing - oic', ['Marketing - OIC', 'Data Encoder - OIC', 'Marketing', 'Data Encoder']],
            'Marketing - OIC, doubled spaces'  => [' Marketing  -  OIC ', ['Marketing - OIC', 'Data Encoder - OIC', 'Marketing', 'Data Encoder']],
        ];
    }

    #[DataProvider('actorSpellings')]
    public function test_AR2_the_actor_role_is_compared_trimmed_and_case_insensitively(string $actorRole, array $offered): void
    {
        $html = $this->actingAs($this->user($actorRole))->get('/accounts/create')->assertOk()->getContent();

        $this->assertSame($offered, $this->offeredRoles($html));
    }

    public function test_AR2_a_posted_role_is_stored_in_its_canonical_spelling(): void
    {
        $this->actingAs($this->user('CEO'))->post('/accounts', $this->form(['role' => '  data encoder - oic ']))->assertSessionHasNoErrors();

        $id = User::where('email', 'nina@example.test')->value('id');
        $this->assertSame('Data Encoder - OIC', DB::table('employee_profiles')->where('user_id', $id)->value('role'));
    }

    public function test_AR2_the_page_offers_the_roles_each_creator_may_give_in_order(): void
    {
        $ceoHtml = $this->actingAs($this->user('CEO'))->get('/accounts/create')->assertOk()->getContent();
        $this->assertSame(['CEO', 'Marketing - OIC', 'Data Encoder - OIC', 'Marketing', 'Data Encoder'], $this->offeredRoles($ceoHtml));

        $oicHtml = $this->actingAs($this->user('Marketing - OIC'))->get('/accounts/create')->assertOk()->getContent();
        $this->assertSame(['Marketing - OIC', 'Data Encoder - OIC', 'Marketing', 'Data Encoder'], $this->offeredRoles($oicHtml));
    }

    public function test_AR2_the_form_carries_a_csrf_token(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/accounts/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*\/accounts"[^>]*>\s*<input type="hidden" name="_token"/', $html);
    }

    public function test_AR2_no_other_field_reaches_the_new_account(): void
    {
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->post('/accounts', $this->form([
            'created_by' => 999, 'password_plain' => 'forged', 'remember_token' => 'forged', 'id' => 4242,
        ]))->assertSessionHasNoErrors();

        $row = DB::table('users')->where('email', 'nina@example.test')->first();
        $this->assertNotSame(4242, (int) $row->id);
        $this->assertSame($ceo->id, (int) $row->created_by);
        $this->assertSame('nina-pass-1', $row->password_plain);
        $this->assertNotSame('forged', $row->remember_token);
    }

    /** The values of the role dropdown's options, without the "Choose a role" placeholder. */
    private function offeredRoles(string $html): array
    {
        $this->assertSame(1, preg_match('/<select id="role" name="role"[^>]*>(.*?)<\/select>/s', $html, $m));
        preg_match_all('/<option value="([^"]*)"/', $m[1], $o);

        return array_values(array_filter(array_map('html_entity_decode', $o[1]), fn ($v) => $v !== ''));
    }
}
