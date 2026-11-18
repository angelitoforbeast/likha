<?php

namespace Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;

/**
 * AR-3: the password is stored as /owner/users stores the ones it sets (bcrypt + password_plain), users.created_by
 * keeps who made the account, and the CEO sees both on /owner/users. The new-account page never shows a password.
 */
class AccountCreatorTest extends AccountsTestCase
{
    public function test_AR3_an_account_by_a_marketing_oic_is_on_owner_users_with_its_password_and_creator(): void
    {
        $oic = $this->user('Marketing - OIC', 'Olivia Cruz', 'olivia@example.test');
        $ceo = $this->user('CEO', 'Rosa Villanueva');

        $before = now()->subSecond();
        $this->actingAs($oic)->post('/accounts', $this->form())->assertSessionHasNoErrors();

        $row = DB::table('users')->where('email', 'nina@example.test')->first();
        $this->assertSame('nina-pass-1', $row->password_plain);
        $this->assertSame($oic->id, (int) $row->created_by);
        $this->assertTrue(now()->addSecond()->gte($row->created_at) && $before->lte($row->created_at), 'created_at is now');

        $html = $this->actingAs($ceo)->get('/owner/users')->assertOk()->getContent();
        $this->assertStringContainsString('Created by', $html);

        $tr = $this->rowOf($html, 'nina@example.test');
        $this->assertStringContainsString('Nina Santos', $tr);
        $this->assertStringContainsString('nina-pass-1', $tr);
        $this->assertStringContainsString('Olivia Cruz', $tr);
    }

    public function test_AR3_an_account_made_before_this_page_shows_no_creator(): void
    {
        $ceo = $this->user('CEO', 'Rosa Villanueva');
        $this->user('Marketing', 'Old Staff', 'old@example.test');

        $html = $this->actingAs($ceo)->get('/owner/users')->assertOk()->getContent();

        $tr = $this->rowOf($html, 'old@example.test');
        $this->assertStringNotContainsString('Rosa Villanueva', $tr);
        $this->assertMatchesRegularExpression('/data-created-by[^>]*>\s*—\s*</', $tr);
    }

    public function test_AR3_the_creator_falls_back_to_the_email_without_a_profile_name(): void
    {
        $oic = $this->user('Marketing - OIC', 'Olivia Cruz', 'olivia@example.test');
        $ceo = $this->user('CEO');
        $this->actingAs($oic)->post('/accounts', $this->form())->assertSessionHasNoErrors();
        DB::table('employee_profiles')->where('user_id', $oic->id)->update(['name' => null]);

        $tr = $this->rowOf($this->actingAs($ceo)->get('/owner/users')->getContent(), 'nina@example.test');
        $this->assertStringContainsString('olivia@example.test', $tr);
    }

    public function test_AR3_the_new_account_page_never_shows_a_password(): void
    {
        $oic = $this->user('Marketing - OIC', 'Olivia Cruz', 'olivia@example.test');
        DB::table('users')->where('id', $oic->id)->update(['password_plain' => 'olivia-own-secret']);
        $other = $this->user('Marketing', 'Other Staff', 'other@example.test');
        DB::table('users')->where('id', $other->id)->update(['password_plain' => 'other-staff-secret']);

        $this->actingAs($oic)->post('/accounts', $this->form())->assertSessionHasNoErrors();
        $html = $this->actingAs($oic)->get('/accounts/create')->assertOk()->getContent();

        $this->assertStringContainsString('Account created.', $html);
        foreach (['olivia-own-secret', 'other-staff-secret', 'nina-pass-1', 'secret-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
        }
    }

    public function test_AR3_owner_users_stays_ceo_only(): void
    {
        $this->actingAs($this->user('Marketing - OIC'))->get('/owner/users')->assertNotFound();
    }

    /** The <tr> of /owner/users that holds this email. */
    private function rowOf(string $html, string $email): string
    {
        foreach (preg_split('/<tr\b/', $html) as $chunk) {
            if (str_contains($chunk, $email)) {
                return explode('</tr>', $chunk)[0];
            }
        }
        $this->fail("No row for $email on /owner/users.");
    }
}
