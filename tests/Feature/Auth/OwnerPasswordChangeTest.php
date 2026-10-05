<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowedIpMiddleware;
use Illuminate\Support\Facades\Hash;

/**
 * POST /owner/users/{id}/password (OwnerUsersController::updatePassword): ang nag-iisang lugar sa app na
 * nagpapalit ng password. Pin ng kasalukuyang ugali, tapos ang A2 ng Amendment 012-1.
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
