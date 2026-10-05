<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Amendment 012-2 (B1, B2): ang mga session na bukas na sa ibang browser. Dito lang sa file na ito
 * naka-`database` ang session driver (gaya ng live), dahil ang mga row sa `sessions` ang binubura.
 * Ang ibang Auth test ay nasa default na `array` driver: sila ang patunay na walang nasisira doon.
 */
class EndOpenSessionsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: hindi ito ang sinusubok dito (gaya ng OwnerPasswordChangeTest).
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        // Database driver sa config lang ng test. Naka-cache ang `session.store` at ang guard kapag
        // na-resolve na sila sa `array`, kaya nililimot muna para sa bagong driver sila kumapit.
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();

        // Parehong mga column ng migration na 0001_01_01_000000_create_users_table.
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function test_non_ceo_logout_leaves_the_other_session_of_that_user_signed_in(): void
    {
        $staff = $this->user('Marketing', 'staff@example.test');
        $pressed = $this->loginInNewBrowser($staff);
        $other = $this->loginInNewBrowser($staff);

        // Patunay na database driver talaga: may row ang bawat login, na may user id.
        $this->assertSame(2, $this->sessionRowsOf($staff));

        $this->freshBrowser();
        $this->withCookie(session()->getName(), $pressed)->post('/logout')->assertRedirect('/login');

        $this->assertSentToLogin($this->getWithSessionOnly($pressed));
        $this->getWithSessionOnly($other)->assertOk();
        $this->assertSame(1, $this->sessionRowsOf($staff));
    }

    /**
     * Dagdag sa "bagong browser" para sa database driver: ang handler ang sumusulat ng `sessions.user_id`
     * mula sa `auth.driver`, na singleton sa container. Sa totoong request bago ito lagi; sa iisang app ng
     * test, kapag hindi nilimot, ang user ng naunang request ang maisusulat sa row ng susunod.
     */
    protected function freshBrowser(): void
    {
        parent::freshBrowser();

        $this->app->forgetInstance('auth.driver');
    }

    /** Password login sa bagong browser; ibinabalik ang session id na hawak ng browser na iyon. */
    private function loginInNewBrowser(User $user): string
    {
        $this->freshBrowser();
        [$response] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);
        $response->assertRedirect('/');

        return session()->getId();
    }

    /**
     * Request sa protektadong page na session cookie LANG ang dala (walang remember cookie at walang
     * pangalawang cookie), para ang sagot ay galing lang sa session at hindi sa cookie sign-in.
     */
    private function getWithSessionOnly(string $sessionId): TestResponse
    {
        $this->freshBrowser();
        unset(
            $this->defaultCookies[$this->recallerName()],
            $this->defaultCookies[self::SINCE_COOKIE],
            $this->unencryptedCookies[self::SINCE_COOKIE]
        );

        return $this->withCookie(session()->getName(), $sessionId)->get(self::PROTECTED_URL);
    }

    private function sessionRowsOf(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }
}
