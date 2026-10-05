<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Listeners\RefuseRememberedLoginUnlessCeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            // CEO lang ang may "remembered" login na 30 araw (handoff 012). Ang role ay binabasa lang
            // PAGKATAPOS tanggapin ang password (kaya pareho ang bigong login may account man o wala), at
            // galing sa account sa database, hindi sa form: walang binabasang `remember` na request parameter.
            if (RefuseRememberedLoginUnlessCeo::isCeo(Auth::user())) {
                Auth::guard()->setRememberDuration(RefuseRememberedLoginUnlessCeo::REMEMBER_MINUTES); // 30 araw mula sa login
                Auth::login(Auth::user(), true); // ito ang naglalabas ng remember cookie

                // Amendment 012-1 (A1): pangalawang cookie na may user id at oras ng login na ito, naka-encrypt
                // ng framework. Ito ang binabasa ng server para sa 30 araw; hindi umaasa sa expiry ng browser.
                Cookie::queue(
                    RefuseRememberedLoginUnlessCeo::SINCE_COOKIE,
                    RefuseRememberedLoginUnlessCeo::sinceCookieValue(Auth::id()),
                    RefuseRememberedLoginUnlessCeo::REMEMBER_MINUTES
                );
            }

            $request->session()->regenerate();
            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user(); // kinukuha bago mag-logout, para sa B2 sa ibaba
        // Binabasa ang role BAGO ang logout (query ito sa profile): kapag pumalya, walang nagagalaw, sa halip
        // na matapos ang session na ito habang buhay pa ang iba pang session ng CEO.
        $isCeo = RefuseRememberedLoginUnlessCeo::isCeo($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Amendment 012-2 (B2): ang Logout ng CEO ay sign-out sa lahat ng device: tinatapos din ang iba pang
        // session ng account na iyon. Sadyang nandito at hindi sa Logout event, dahil ang event na iyon ay
        // tumatakbo rin kapag tinanggihan ang isang cookie sign-in. Sa ibang role, walang binubura.
        if ($isCeo) {
            RefuseRememberedLoginUnlessCeo::endSessionsOf($user->getAuthIdentifier());
        }

        return redirect('/login');
    }
}