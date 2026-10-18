<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the ORIGINAL account admin (the user who created the company),
 * so subscription + usage/billing management is never exposed to ordinary users under
 * the account. Platform admins — including while impersonating a tenant — always pass,
 * so they can manage a company on its behalf.
 */
class EnsureCompanyOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $allowed = $user && (
            ($user->is_platform_admin ?? false)
            || $request->session()->get('impersonate_company_id')
            || $this->ownsCompany($user)
        );

        if (! $allowed) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                abort(403, 'Only the account owner can manage the subscription and billing.');
            }

            return redirect()->route('dashboard')
                ->with('error', 'Only the account owner can manage the subscription and billing.');
        }

        return $next($request);
    }

    private function ownsCompany($user): bool
    {
        if (! ($user->company_id ?? null)) {
            return false;
        }

        $createdBy = (int) DB::table('companies')->where('id', $user->company_id)->value('created_by');

        return $createdBy === (int) $user->id;
    }
}
