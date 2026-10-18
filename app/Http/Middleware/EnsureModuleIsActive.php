<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsActive
{
    public function __construct(private CompanyEntitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $isActive = Cache::remember("module_active_{$moduleCode}", 300, fn () =>
            DB::table('security_modules')
                ->where('code_name', $moduleCode)
                ->where('active', 1)
                ->exists()
        );

        if (! $isActive) {
            return $this->block($request, 'The requested module is not currently active on your subscription.');
        }

        // Entitlement gate: SaaS → the company's plan must include the module;
        // self-hosted → the signed licence must grant it. Unrestricted for the
        // platform admin / console (no active company).
        if (! $this->entitlements->currentAllowsModule($moduleCode)) {
            return $this->block($request, 'This module is not included in your plan. Please upgrade or contact your provider.');
        }

        return $next($request);
    }

    private function block(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            abort(403, $message);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
