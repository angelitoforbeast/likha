<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class RoleNameCheck
{
    public function handle($request, Closure $next, ...$roles)
    {
        $user = Auth::user();

        // Mas maayos gamitin built-in method ng Spatie
        if (!$user || !$user->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
