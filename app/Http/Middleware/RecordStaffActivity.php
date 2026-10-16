<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FromJntController;
use App\Http\Controllers\MacroOutputController;
use App\Support\StaffEvents;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para malaman ng owner kung sino ang aktibo at ano ang huling ginawa ng bawat staff:
 *  1) last_seen_at / last_seen_path ng naka-login na user, pinakamadalas isang beses kada minuto (cache throttle);
 *  2) isang staff_events row kada matagumpay (2xx/3xx) na POST/PUT/PATCH/DELETE.
 *
 * Route name, method, status at id lang ang naitatala: hindi binabasa ang body o ang query string, kaya walang
 * customer data, password o pera na pumapasok. Ang CSRF failure (419) ay hindi umaabot sa event. Ang login at
 * logout POST ay hindi kailanman dito naitatala, kahit naka-login na ang session bago mag-login ulit (pareho ang user
 * bago at pagkatapos): ang StaffLoginActivity listener ang bahala sa mga iyon.
 * Hindi kailanman nagtatapon ng error sa request.
 */
class RecordStaffActivity
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Ang login at logout action: ang listener ang nagtatala ng mga ito bilang `login` / `logout`. */
    private const AUTH_ACTIONS = [LoginController::class . '@login', LoginController::class . '@logout'];

    /** Ang Checker 1 at J&T status actions: sila mismo ang nagtatala ng checker.* event kada order na nagbago. */
    private const CHECKER_ACTIONS = [
        MacroOutputController::class . '@updateField',
        MacroOutputController::class . '@bulkUpdate',
        MacroOutputController::class . '@validateItems',
        MacroOutputController::class . '@validateAddresses',
        MacroOutputController::class . '@validateCheckerToFix',
        FromJntController::class . '@updateOrInsert',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $userBefore = $this->userId();

        $response = $next($request);

        try {
            $userAfter = $this->userId();
            if ($userAfter !== null) {
                $this->touchLastSeen($userAfter, $request);
            }

            $status = $response->getStatusCode();
            if ($userBefore !== null && $userBefore === $userAfter
                && in_array($request->getMethod(), self::WRITE_METHODS, true)
                && $status >= 200 && $status < 400
                && !$this->isLoginOrLogout($request)
                && !$this->isCheckerAction($request)) {
                [$kind, $ref] = $this->describe($request);
                StaffEvents::record($userBefore, $kind, $request->getMethod(), $status, $ref);
            }
        } catch (\Throwable $e) {
            Log::warning('RecordStaffActivity: nilaktawan', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function userId(): ?int
    {
        try {
            $id = Auth::id();
        } catch (\Throwable $e) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }

    private function isLoginOrLogout(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && in_array($route->getActionName(), self::AUTH_ACTIONS, true);
    }

    private function isCheckerAction(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && in_array($route->getActionName(), self::CHECKER_ACTIONS, true);
    }

    private function touchLastSeen(int $userId, Request $request): void
    {
        // Cache::add ay true lang kung wala pa ang key: isang update kada user kada minuto.
        if (!Cache::add("staff_last_seen:{$userId}", 1, 60)) {
            return;
        }
        if (!Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }
        DB::table('users')->where('id', $userId)->update([
            'last_seen_at'   => now(),
            'last_seen_path' => mb_substr($request->path(), 0, 255),
        ]);
    }

    /** [kind, ref]: route name (o "METHOD path-pattern"), at ang id-like na route parameter kung meron. */
    private function describe(Request $request): array
    {
        $route = $request->route();
        if (!$route instanceof Route) {
            return [$request->getMethod() . ' ' . $request->path(), null];
        }

        $kind = $route->getName() ?: $request->getMethod() . ' ' . $route->uri();

        // Orihinal na URL segment (bago ang model binding): id-like na pangalan muna, kung wala ang una.
        $params = $route->originalParameters();
        foreach ($params as $name => $value) {
            if (preg_match('/^id$|_id$|Id$/', (string) $name) && is_scalar($value)) {
                return [$kind, (string) $value];
            }
        }
        $first = $params ? reset($params) : null;

        return [$kind, is_scalar($first) ? (string) $first : null];
    }
}
