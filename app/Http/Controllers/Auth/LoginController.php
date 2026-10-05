<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Listeners\RefuseRememberedLoginUnlessCeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        // CEO lang ang may "remembered" login na 30 araw (handoff 012). Ang role ay galing sa account sa
        // database, hindi sa form: walang binabasang `remember` na request parameter.
        $remember = RefuseRememberedLoginUnlessCeo::isCeo(Auth::getProvider()->retrieveByCredentials($credentials));
        if ($remember) {
            Auth::guard()->setRememberDuration(60 * 24 * 30); // minuto = 30 araw mula sa login
        }

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();
            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}