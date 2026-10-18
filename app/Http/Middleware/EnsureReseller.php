<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the reseller self-service portal. The signed-in user must be the login user of an
 * ACTIVE reseller (resellers.user_id). Resolves that reseller and shares it on the request
 * so portal controllers can scope everything to it. Platform admins also pass (they may
 * inspect the portal), but only if they carry a reseller link.
 */
class EnsureReseller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // A team login (owner or staff) first; then the original single login, which is an owner.
        $member = $user ? ResellerUser::query()->where('user_id', $user->id)->first() : null;
        $reseller = $member?->reseller ?? ($user ? Reseller::where('user_id', $user->id)->first() : null);

        // Abort (never redirect to the tenant dashboard) — a reseller-role user is funnelled
        // here by EnforceCrudPermissions, so redirecting back to '/' would loop.
        if (! $reseller) {
            abort(403, 'No reseller account is linked to this login.');
        }

        if (! $reseller->isActive()) {
            abort(403, 'Your reseller account is suspended. Please contact the platform administrator.');
        }

        $request->attributes->set('reseller', $reseller);
        $request->attributes->set('reseller_role', $member?->role ?? 'owner');

        return $next($request);
    }
}
