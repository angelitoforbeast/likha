<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AllowedIps;

class RestrictByIp
{
    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip(); // get client IP

        if (!AllowedIps::where('ip_address', $ip)->exists()) {
            abort(403, 'Access denied. Your IP is not allowed.');
        }

        return $next($request);
    }
}
