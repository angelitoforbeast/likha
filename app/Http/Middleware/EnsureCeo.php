<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCeo
{
    public function handle(Request $request, Closure $next)
    {
        $role = auth()->user()->employeeProfile?->role;

        if ($role !== 'CEO') {
            abort(403);
        }

        return $next($request);
    }
}
