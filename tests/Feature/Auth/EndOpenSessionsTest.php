<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function test_ceo_logout_ends_every_session_of_that_account_and_no_others(): void
    {
        $ceo = $this->user('CEO', 'ceo@example.test');
        $bystander = $this->user('Marketing', 'bystander@example.test');
        $pressed = $this->loginInNewBrowser($ceo);
        $other = $this->loginInNewBrowser($ceo);
        $bystanderSession = $this->loginInNewBrowser($bystander);

        $this->freshBrowser();
        $this->withCookie(session()->getName(), $pressed)->post('/logout')->assertRedirect('/login');

        $this->assertSentToLogin($this->getWithSessionOnly($pressed));
        $this->assertSentToLogin($this->getWithSessionOnly($other));
        $this->getWithSessionOnly($bystanderSession)->assertOk();
        $this->assertSame(0, $this->sessionRowsOf($ceo));
    }

    /** B1: kaninong password ang pinapalitan ng CEO, at aling session lang ang dapat matapos. */
    public static function whosePasswordIsChanged(): array
    {
        return [
            'password ng ibang user' => ['staff', 'staff'],
            'sariling password'      => ['ceo', 'ceo sa ibang browser'],
        ];
    }

    #[DataProvider('whosePasswordIsChanged')]
    public function test_password_change_ends_the_open_sessions_of_that_user_and_no_others(string $target, string $ended): void
    {
        $users = [
            'ceo'       => $this->user('CEO', 'ceo@example.test'),
            'staff'     => $this->user('Marketing', 'staff@example.test'),
            'bystander' => $this->user('Marketing', 'bystander@example.test'),
        ];
        $sessions = [
            'ceo na nagpapalit'    => $this->loginInNewBrowser($users['ceo']),
            'ceo sa ibang browser' => $this->loginInNewBrowser($users['ceo']),
            'staff'                => $this->loginInNewBrowser($users['staff']),
            'bystander'            => $this->loginInNewBrowser($users['bystander']),
        ];

        // withCredentials: ang JSON request ng test ay hindi nagdadala ng cookie kung wala ito.
        $this->freshBrowser();
        $this->withCredentials()
            ->withCookie(session()->getName(), $sessions['ceo na nagpapalit'])
            ->postJson(route('owner.users.password', $users[$target]->id), ['password' => 'bagong-password'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'id' => $users[$target]->id, 'password' => 'bagong-password']);

        foreach ($sessions as $name => $sessionId) {
            $response = $this->getWithSessionOnly($sessionId);

            if ($name === $ended) {
                $this->assertSentToLogin($response);
            } else {
                $this->assertSame(200, $response->status(), "Natapos ang session na hindi dapat: {$name}");
            }
        }
    }

    public function test_password_stays_when_the_sessions_of_that_user_cannot_be_ended(): void
    {
        $ceo = $this->user('CEO', 'ceo@example.test');
        $staff = $this->user('Marketing', 'staff@example.test');
        $ceoSession = $this->loginInNewBrowser($ceo);
        $this->loginInNewBrowser($staff);

        // Pumapalya ang pagbura sa sessions (sqlite trigger), gaya ng DB error sa gitna ng request.
        DB::statement("CREATE TRIGGER sessions_no_delete BEFORE DELETE ON sessions BEGIN SELECT RAISE(ABORT, 'no delete'); END");

        $this->freshBrowser();
        $response = $this->withCredentials()
            ->withCookie(session()->getName(), $ceoSession)
            ->postJson(route('owner.users.password', $staff->id), ['password' => 'bagong-password']);

        $response->assertStatus(500);
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->fresh()->password), 'Napalitan ang password kahit hindi natapos ang mga session.');
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
