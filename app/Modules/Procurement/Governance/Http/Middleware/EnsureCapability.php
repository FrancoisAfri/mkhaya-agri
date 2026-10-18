<?php

namespace App\Modules\Procurement\Governance\Http\Middleware;

use App\Modules\Procurement\Governance\Services\Capabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §12: gates a route group on one capability inside the module.
 *
 * `module.active:procurement_governance` already decided whether the tenant may be here at all.
 * This decides whether *this capability* is one they bought or switched on — so a tenant without
 * Deviation Management gets a clear refusal on /procurement-governance/deviations while the rest of
 * the module keeps working.
 *
 * Applied as `procgov.capability:deviations`.
 */
class EnsureCapability
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if ($this->capabilities->has($capability)) {
            return $next($request);
        }

        $message = 'This procurement capability is not enabled for your organisation. '
            .'An administrator can switch it on in Procurement Governance settings, '
            .'or it may not be included in your plan.';

        if ($request->expectsJson() || $request->header('X-Inertia')) {
            abort(403, $message);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
