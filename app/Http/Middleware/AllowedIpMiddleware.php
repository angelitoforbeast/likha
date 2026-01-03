<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AllowedIp;
use Illuminate\Support\Facades\Log;

class AllowedIpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // ✅ Allow public pages (e.g., login) without restriction
        if (!auth()->check()) {
            return $next($request);
        }

        // Get role (supports your employeeProfile->role setup)
        $role = auth()->user()->employeeProfile?->role
            ?? auth()->user()->role
            ?? null;

        // ✅ CEO exempted kahit anong IP
        if ($role === 'CEO') {
            return $next($request);
        }

        // ✅ Optional dev safety (kept as you had it)
        if (app()->environment('local')) {
            return $next($request);
        }

        // ✅ Detect client IP
        $ip = $request->ip();

        // ✅ DEBUG LOG (temporary; helps you confirm Heroku proxy IP issues)
        Log::warning('ALLOWED_IP_CHECK', [
            'user_id' => auth()->id(),
            'role' => $role,
            'ip' => $ip,
            'remote_addr' => $request->server('REMOTE_ADDR'),
            'x_forwarded_for' => $request->header('x-forwarded-for'),
            'x_real_ip' => $request->header('x-real-ip'),
            'cf_connecting_ip' => $request->header('cf-connecting-ip'),
        ]);

        // ✅ Safety guard: if app is seeing localhost, something is wrong (proxy)
        // (Optional; you can remove later)
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            abort(403, "Access denied (proxy/local IP detected): {$ip}");
        }

        // ✅ Check against DB allowed list
        $allowed = AllowedIp::where('ip_address', $ip)->exists();

        if (!$allowed) {
            abort(403, "Access denied. Your IP is not allowed: {$ip}");
        }

        return $next($request);
    }
}
