<?php

namespace Tests\Feature\StaffActivity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sa totoong login route at LoginController: isang `login` row kada matagumpay na login, isang `logout`
 * row kada logout, laging naitatakda ang last_login_at, at hindi kailanman naitatala ng middleware ang
 * mismong login o logout POST.
 */
class LoginEventsTest extends StaffActivityTestCase
{
    private function postLogin($user)
    {
        return $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
    }

    private function kinds(): array
    {
        return $this->events()->map(fn ($e) => [(int) $e->user_id, $e->kind])->all();
    }

    private function lastLogin($user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('last_login_at');
    }

    public function test_one_login_writes_one_login_event_and_sets_last_login_at(): void
    {
        $user = $this->staff();
        Carbon::setTestNow('2026-10-16 15:00:00');

        $this->postLogin($user)->assertRedirect('/');

        $this->assertSame([[$user->id, 'login']], $this->kinds());
        $this->assertSame('2026-10-16 15:00:00', (string) $this->lastLogin($user));
    }

    public function test_logging_in_again_on_a_signed_in_session_writes_one_login_event_and_no_post_login(): void
    {
        $user = $this->staff();
        $this->postLogin($user)->assertRedirect('/');
        Carbon::setTestNow('2026-10-16 15:10:00');

        $this->postLogin($user)->assertRedirect('/');

        $this->assertSame([[$user->id, 'login'], [$user->id, 'login']], $this->kinds());
        $this->assertSame('2026-10-16 15:10:00', (string) $this->lastLogin($user));
    }

    public function test_a_ceo_login_writes_one_login_event(): void
    {
        $ceo = $this->staff('CEO');

        $this->postLogin($ceo)->assertRedirect('/');

        $this->assertSame([[$ceo->id, 'login']], $this->kinds());
        $this->assertNotNull($this->lastLogin($ceo));
    }

    public function test_logout_writes_one_logout_event(): void
    {
        $user = $this->staff();
        $this->postLogin($user)->assertRedirect('/');

        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame([[$user->id, 'login'], [$user->id, 'logout']], $this->kinds());
    }

    public function test_a_second_user_logging_in_gets_their_own_last_login_at(): void
    {
        $first = $this->staff();
        $second = $this->staff();
        Carbon::setTestNow('2026-10-16 15:00:00');
        $this->postLogin($first)->assertRedirect('/');
        Carbon::setTestNow('2026-10-16 15:20:00');

        $this->postLogin($second)->assertRedirect('/');

        $this->assertSame('2026-10-16 15:00:00', (string) $this->lastLogin($first));
        $this->assertSame('2026-10-16 15:20:00', (string) $this->lastLogin($second));
        $this->assertSame([[$first->id, 'login'], [$second->id, 'login']], $this->kinds());
    }
}
