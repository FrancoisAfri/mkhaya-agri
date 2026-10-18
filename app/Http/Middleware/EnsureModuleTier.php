<?php

namespace App\Http\Middleware;

use App\Support\Hubs\HubAccess;
use App\Support\Hubs\HubRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a hubbed module's role tiers. The required tier for the requested path is looked up
 * from the module's ModuleHubs config (data-driven, no per-route config) and compared to the
 * user's module access level (HubAccess). This stops, for example, a WRITE front-line rep/agent
 * reaching an ADMIN Settings/Configure action by typing the URL — so the hub role separation is
 * real, not just visual.
 *
 * Applied once to a module's whole route group as `module.tier:<code_name>`.
 */
class EnsureModuleTier
{
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $hubs = HubRegistry::for($moduleCode);
        if (! $hubs) {
            return $next($request); // module not hub-managed — nothing to enforce.
        }

        $required = $hubs::pathTier($request->path());
        $level = HubAccess::level($request->user(), $moduleCode);

        if ($level < $required) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                abort(403, 'You do not have permission for this area.');
            }

            return redirect()->route('dashboard')->with('error', 'You do not have permission for this area.');
        }

        return $next($request);
    }
}
