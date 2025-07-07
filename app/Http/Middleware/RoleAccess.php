<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoleAccess
{
    public function handle($request, Closure $next, ...$roles)
    {
        if (!Auth::check()) {
            abort(403, 'Not authenticated');
        }

        $userId = Auth::id();

        // Optional debug: Uncomment this to check role
        /*
        dd([
            'user_id' => $userId,
            'role' => DB::table('user_roles')
                        ->where('user_id', $userId)
                        ->value('role_name'),
        ]);
        */

        $userRole = DB::table('user_roles')
            ->where('user_id', $userId)
            ->value('role_name');

        if (in_array($userRole, $roles)) {
            return $next($request);
        }

        abort(403, 'Access denied for your role.');
    }
}
