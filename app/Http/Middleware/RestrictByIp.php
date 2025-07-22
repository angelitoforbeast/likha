<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AllowedIps;

class RestrictByIp
{
    public function handle($request, \Closure $next)
{
    $allowedIps = [
        '158.62.1.240', // ✅ Palitan mo ito ng sarili mong IP
        '::1', // ✅ Para sa localhost (optional)
    ];

    if (!in_array($request->ip(), $allowedIps)) {
        abort(403, 'Access denied. IP not authorized.');
    }

    return $next($request);
}

}
