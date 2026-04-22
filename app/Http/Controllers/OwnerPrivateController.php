<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class OwnerPrivateController extends Controller
{
    public function index()
    {
        $roleRaw  = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;

        if (!$isCEO) abort(404);

        return view('owner.private');
    }
}
