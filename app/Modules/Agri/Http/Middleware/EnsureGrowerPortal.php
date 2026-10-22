<?php

namespace App\Modules\Agri\Http\Middleware;

use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything a grower portal request must satisfy before it reaches a controller.
 *
 * Who is signed in on the grower guard; that the account is still open (a revoked grower is out on
 * their very next click, not at their next sign-in); and the organisation, taken from the stored
 * account and never from the request, put in place for the app scope and row-level security. The
 * grower is then handed to the controllers, which filter every read on it.
 */
class EnsureGrowerPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        // No tenant in play while the account is read: not one left on a pooled connection, and not a
        // staff sign-in's in the same browser.
        app(CurrentCompany::class)->clear();
        $this->stamp(null);
        /** @var GrowerAccount|null $account */
        $account = Auth::guard('grower_portal')->user();

        // Re-read: a revocation made a moment ago by the company counts on this very request.
        $account = $account ? GrowerAccount::withoutGlobalScopes()->find($account->getAuthIdentifier()) : null;
        if (! $account) {
            return $request->expectsJson() ? response()->json(['message' => 'Please sign in.'], 401) : redirect()->route('grower.portal.login');
        }
        if ($account->status === GrowerAccount::REVOKED) {
            Auth::guard('grower_portal')->logout();
            $request->session()->invalidate();

            return redirect()->route('grower.portal.login')->withErrors(['identifier' => 'Your access to the grower portal was withdrawn. Please contact the company.']);
        }

        app(CurrentCompany::class)->set((int) $account->company_id);
        $this->stamp((int) $account->company_id);
        $request->attributes->set('grower_account', $account);

        return $next($request);
    }

    private function stamp(?int $companyId): void
    {
        // As ResolveCompany: feature tests run with the RLS session variable unset unless they opt in.
        if (app()->environment('testing') && ! config('tenancy.stamp_rls_in_tests', false)) {
            return;
        }
        try {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [$companyId === null ? '' : (string) $companyId]);
        } catch (\Throwable) {
            // best effort; the app-level company scope still applies
        }
    }
}
