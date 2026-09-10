<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on the authenticated user's role.
 *
 * Usage: `->middleware('role:admin')`, or `'role:admin,support'` to accept
 * either. These routes sit inside the `auth:sanctum` group, so a guest never
 * reaches here — but a null user is still treated as forbidden rather than
 * waved through, so the middleware stays correct if the grouping changes.
 *
 * Returns JSON rather than calling abort(), so the response shape does not
 * depend on whether the caller remembered to send `Accept: application/json`.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json([
                'error' => 'This action requires an administrator account.',
            ], 403);
        }

        return $next($request);
    }
}
