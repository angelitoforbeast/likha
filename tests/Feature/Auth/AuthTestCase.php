<?php

namespace Tests\Feature\Auth;

use App\Listeners\RefuseRememberedLoginUnlessCeo;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base ng mga login / logout / remember-cookie test (sqlite in-memory), gaya ng OwnerPrivateTestCase:
 * gawa sa kamay ang users at employee_profiles. Kasama ang tasks at everyday_tasks dahil ang listener na
 * CopyEverydayTasksOnLogin ay nagbabasa sa mga iyon tuwing may Login event.
 */
abstract class AuthTestCase extends TestCase
{
    protected const PASSWORD = 'secret-password';

    /** Page na nasa likod ng ['web','auth'] lang: JSON, walang view, walang role check. */
    protected const PROTECTED_URL = '/debug/ip';

    /** Pangalawang cookie ng CEO (Amendment 012-1, A1): user id at oras ng password login. */
    protected const SINCE_COOKIE = RefuseRememberedLoginUnlessCeo::SINCE_COOKIE;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('everyday_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
            $t->date('due_date')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    protected function user(string $role, string $email = 'user@example.test'): User
    {
        $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => self::PASSWORD]);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    protected function recallerName(): string
    {
        return Auth::guard()->getRecallerName();
    }

    /**
     * POST /login na may kilalang session id sa cookie, para makita kung napalitan ang id (regenerate).
     * Ibinabalik ang [response, session id bago ang request].
     */
    protected function postLogin(array $form): array
    {
        $sessionId = Str::random(40);

        $response = $this->from('/login')
            ->withCookie(session()->getName(), $sessionId)
            ->post('/login', $form);

        return [$response, $sessionId];
    }

    /**
     * Bagong browser na walang session: nililinis ang lahat ng naiwan ng naunang request sa memory
     * (session data at session cookie, naka-cache na guard, mga cookie na naka-queue) para ang susunod na
     * request ay umasa lang sa cookie na ipapadala ng test.
     */
    protected function freshBrowser(): void
    {
        session()->flush();
        session()->setId(null);
        unset($this->defaultCookies[session()->getName()]);
        Auth::forgetGuards();
        Cookie::flushQueuedCookies();
    }

    /**
     * Request sa protektadong page gamit lang ang ibinigay na remember cookie (bagong browser), at ang
     * pangalawang cookie ng Amendment 012-1 kung may ibinigay. Walang $since = hindi ito ipinapadala.
     */
    protected function getWithRememberCookie(string $value, ?string $since = null): TestResponse
    {
        $this->freshBrowser();

        // Ang withCookie ay nananatili sa mga susunod na request ng parehong test, kaya tinatanggal muna.
        unset($this->defaultCookies[self::SINCE_COOKIE], $this->unencryptedCookies[self::SINCE_COOKIE]);
        if ($since !== null) {
            $this->withCookie(self::SINCE_COOKIE, $since);
        }

        return $this->withCookie($this->recallerName(), $value)->get(self::PROTECTED_URL);
    }

    /**
     * Password login ng CEO; ibinabalik ang [remember cookie, pangalawang cookie] na ibinigay ng server
     * (mga halagang na-decrypt na, gaya ng nababasa ng server).
     */
    protected function loginAndTakeCookies(User $user): array
    {
        [$response] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);

        $remember = $response->getCookie($this->recallerName());
        $this->assertNotNull($remember, 'Walang remember cookie sa login ng CEO.');
        $since = $response->getCookie(self::SINCE_COOKIE);
        $this->assertNotNull($since, 'Walang pangalawang cookie sa login ng CEO.');

        return [$remember->getValue(), $since->getValue()];
    }

    /** Ang cookie na naka-encrypt pa, gaya ng hawak ng browser. */
    protected function rawCookie(TestResponse $response, string $name): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        $this->fail("Walang cookie na {$name} sa response.");
    }

    protected function assertSentToLogin(TestResponse $response): void
    {
        $response->assertRedirect('/login');
        $response->assertDontSee('xff');
    }
}
