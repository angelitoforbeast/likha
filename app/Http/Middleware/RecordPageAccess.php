<?php

namespace App\Http\Middleware;

use App\Support\AccessRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 294: whether the signed-in user's role may open the page (AccessRule), on every signed-in GET that renders a
 * page. In `record` mode (the default) it never blocks: a would-block or a page no nav link covers is only counted in
 * access_records, under the route's URI pattern (never the raw URL or query string). In `enforce` mode a would-block
 * answers a plain 403 page. Controllers keep their own checks; this never loosens them.
 *
 * Never throws into a request: a failure of the rule logs a warning and allows, in either mode.
 */
class RecordPageAccess
{
    public function __construct(private AccessRule $rule)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $page = $this->pageRequest($request);

        if ($page !== null && $this->safely(fn () => $this->rule->mode() === AccessRule::ENFORCE)) {
            $decision = $this->safely(fn () => $this->rule->decide($this->role(), $request->path()));
            if ($decision === AccessRule::WOULD_BLOCK) {
                $this->safely(fn () => $this->rule->record($page['user'], $this->role(), $page['pattern'], $decision));

                return response()->view('access.blocked', [], 403);
            }
            $response = $next($request);
            if ($decision === AccessRule::UNMAPPED && $this->rendersPage($response)) {
                $this->safely(fn () => $this->rule->record($page['user'], $this->role(), $page['pattern'], $decision));
            }

            return $response;
        }

        $response = $next($request);

        if ($page !== null && $this->rendersPage($response)) {
            $this->safely(function () use ($request, $page) {
                $decision = $this->rule->decide($this->role(), $request->path());
                if ($decision !== AccessRule::ALLOW) {
                    $this->rule->record($page['user'], $this->role(), $page['pattern'], $decision);
                }
            });
        }

        return $response;
    }

    /**
     * ['user' => id, 'pattern' => '/route/{uri}'] for a signed-in GET page request off the open list; null otherwise.
     * Uses no query: the user comes from the session guard, the rest from the request.
     */
    private function pageRequest(Request $request): ?array
    {
        try {
            if (!$request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
                return null;
            }
            $route = $request->route();
            if (!$route instanceof Route || $this->rule->isOpen($request->path())) {
                return null;
            }
            $userId = Auth::id();
            if ($userId === null) {
                return null;
            }

            return ['user' => (int) $userId, 'pattern' => '/' . ltrim($route->uri(), '/')];
        } catch (\Throwable $e) {
            Log::warning('RecordPageAccess: skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** A page, not JSON or a file download. */
    private function rendersPage(Response $response): bool
    {
        if ($response instanceof JsonResponse || $response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }
        $type = (string) $response->headers->get('Content-Type', '');

        return $type === '' || str_contains($type, 'text/html');
    }

    private function role(): ?string
    {
        return Auth::user()?->employeeProfile?->role;
    }

    /** Runs one step of the rule; a failure logs and gives null, which always means "allow". */
    private function safely(Closure $step): mixed
    {
        try {
            return $step();
        } catch (\Throwable $e) {
            Log::warning('RecordPageAccess: skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
