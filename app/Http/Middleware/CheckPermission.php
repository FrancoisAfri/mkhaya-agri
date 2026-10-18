<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission middleware.
 *
 * Usage in routes:
 *   ->middleware('permission:hr.view')
 *   ->middleware('permission:hr.create|hr.edit')   // any of these
 *   ->middleware('role:admin|hr-manager')
 *
 * Spatie already provides \Spatie\Permission\Middlewares\PermissionMiddleware
 * and RoleMiddleware — this class wraps them to return clean JSON for API
 * routes and Inertia 403 pages for web routes.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (Auth::guest()) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        $user = Auth::user();

        // Support pipe-delimited "any of" syntax: 'hr.view|hr.edit'
        $anyOf = collect($permissions)
            ->flatMap(fn($p) => explode('|', $p))
            ->map(fn($p) => trim($p))
            ->all();

        foreach ($anyOf as $permission) {
            if ($user->hasPermissionTo($permission)) {
                return $next($request);
            }
        }

        return $this->deny($request, 'You do not have permission to perform this action.');
    }

    private function deny(Request $request, string $message, int $status = 403): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        // Inertia-aware redirect with shared error
        abort($status, $message);
    }
}
