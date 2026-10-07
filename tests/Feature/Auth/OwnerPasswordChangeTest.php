<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowedIpMiddleware;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /owner/users/{id}/password (OwnerUsersController::updatePassword): ang nag-iisang lugar sa app na
 * nagpapalit ng password. Pin ng kasalukuyang ugali, tapos ang bagong remember token sa bawat palit.
 */
class OwnerPasswordChangeTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'bagong-password';

    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: hindi ito ang sinusubok dito (gaya ng OwnerPrivateTestCase).
        $this->withoutMiddleware(AllowedIpMiddleware::class);
    }

    public function test_ceo_sets_a_new_password_for_a_user(): void
    {
        $ceo = $this->user('CEO', 'ceo@example.test');
        $target = $this->user('Marketing', 'staff@example.test');

        $response = $this->actingAs($ceo)
            ->postJson(route('owner.users.password', $target->id), ['password' => self::NEW_PASSWORD]);

        $response->assertOk();
        $response->assertExactJson(['ok' => true, 'id' => $target->id, 'password' => self::NEW_PASSWORD]);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->fresh()->password));
    }

    /** Sino ang nagpapalit ng password ng CEO na may remembered login. */
    public static function whoChangesThePassword(): array
    {
        return [
            'ang CEO mismo, sariling password' => [true],
            'ibang CEO account'                => [false],
        ];
    }

    #[DataProvider('whoChangesThePassword')]
    public function test_password_change_ends_the_remembered_logins_of_that_user(bool $self): void
    {
        $target = $this->user('CEO', 'ceo@example.test');
        $actor = $self ? $target : $this->user('CEO', 'ceo2@example.test');
        [$cookie, $since] = $this->loginAndTakeCookies($target);
        $tokenBefore = $target->fresh()->remember_token;

        // Kontrol: bago ang palit, nagsa-sign-in ang dalawang cookie.
        $this->getWithRememberCookie($cookie, $since)->assertOk();

        $this->freshBrowser();
        $this->actingAs($actor)
            ->postJson(route('owner.users.password', $target->id), ['password' => self::NEW_PASSWORD])
            ->assertOk();

        $tokenAfter = $target->fresh()->remember_token;
        $this->assertNotSame($tokenBefore, $tokenAfter);
        $this->assertSame(60, strlen((string) $tokenAfter));

        // Ang lumang remember cookie, kahit wasto pa ang pangalawang cookie, ay hindi na nagsa-sign-in.
        $this->assertSentToLogin($this->getWithRememberCookie($cookie, $since));
        $this->assertGuest();
    }

    public function test_non_ceo_gets_a_404_and_the_password_stays(): void
    {
        $staff = $this->user('Marketing', 'staff@example.test');
        $target = $this->user('CEO', 'ceo@example.test');

        $response = $this->actingAs($staff)
            ->postJson(route('owner.users.password', $target->id), ['password' => self::NEW_PASSWORD]);

        $response->assertNotFound();
        $this->assertTrue(Hash::check(self::PASSWORD, $target->fresh()->password));
    }
}
