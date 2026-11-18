<?php

namespace Tests\Feature\Accounts;

use Illuminate\Support\Facades\Route;

/**
 * AR-4: the public sign-up at /registerlogin is gone. GET and POST both go to /login and nothing is created; the
 * route name `register` no longer exists and no page links to a sign-up.
 */
class PublicRegistrationClosedTest extends AccountsTestCase
{
    private function signUpForm(): array
    {
        return [
            'name'                  => 'Stranger',
            'email'                 => 'stranger@example.test',
            'password'              => 'stranger-pass',
            'password_confirmation' => 'stranger-pass',
        ];
    }

    public function test_AR4_a_guest_post_to_registerlogin_creates_nothing(): void
    {
        $this->post('/registerlogin', $this->signUpForm())->assertRedirect('/login');

        $this->assertSame(0, $this->userCount());
        $this->assertSame(0, $this->profileCount());
        $this->assertGuest();
    }

    public function test_AR4_a_signed_in_post_to_registerlogin_creates_nothing_either(): void
    {
        $this->actingAs($this->user('CEO'));
        $users = $this->userCount();

        $this->post('/registerlogin', $this->signUpForm())->assertRedirect('/login');

        $this->assertSame($users, $this->userCount());
    }

    public function test_AR4_the_sign_up_page_shows_no_form_and_sends_to_login(): void
    {
        $response = $this->get('/registerlogin');

        $response->assertRedirect('/login');
        $this->assertStringNotContainsString('password_confirmation', (string) $response->getContent());
    }

    public function test_AR4_the_register_route_name_is_gone(): void
    {
        $this->assertFalse(Route::has('register'));
    }

    public function test_AR4_the_login_page_offers_no_sign_up(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('Register', $html);
        $this->assertStringNotContainsString('registerlogin', $html);
    }

    public function test_AR4_the_welcome_page_has_no_register_link(): void
    {
        $html = view('welcome')->render();

        $this->assertStringNotContainsString('registerlogin', $html);
        $this->assertStringNotContainsString('>Register<', preg_replace('/\s+/', '', $html));
    }
}
