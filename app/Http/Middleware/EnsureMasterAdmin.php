<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_master_admin) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                abort(403, 'Access restricted to the master administrator.');
            }

            return redirect()->route('dashboard')
                ->with('error', 'Only the master administrator can access this area.');
        }

        return $next($request);
    }
}
