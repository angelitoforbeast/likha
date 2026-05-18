<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

/**
 * Landing page for all JNT-related config screens. Consolidates the per-page
 * navlinks (Accounts, Sender Names, Item Types, etc.) into one entry point
 * so the top nav stays uncluttered. CEO-only by default; individual config
 * routes still handle their own auth.
 */
class JntConfigHubController extends Controller
{
    public function index()
    {
        $role = Auth::user()?->employeeProfile?->role ?? null;
        if ($role !== 'CEO') {
            abort(403, 'JNT Config Hub is CEO-only.');
        }

        return view('jnt.config_hub');
    }
}
