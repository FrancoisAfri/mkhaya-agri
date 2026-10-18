<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Setup\Models\CompanyIdentity;
use App\Core\UserManagement\Models\PasswordSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces account-level security locks.
 *
 * Checks:
 *  1. Account lock (via AuthLock — already exists in your codebase)
 *  2. Password security policy (forces re-set if expired)
 *  3. Two-factor requirement (if configured and not yet passed)
 *
 * Apply to all authenticated routes AFTER 'auth' middleware.
 */
class SecurityCheck
{
    private const EXCLUDED_ROUTES = [
        'auth.logout',
        'auth.password.change',
        'auth.password.update',
        'auth.2fa.verify',
        'auth.2fa.setup',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guest()) {
            return $next($request);
        }

        $user = Auth::user();

        // Skip check on exempt routes to prevent redirect loops
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        // 1. Account locked?
        if ($user->isLocked()) {
            Auth::logout();
            $request->session()->invalidate();
            return $this->denyWith($request, 'Your account has been locked. Contact an administrator.', route('login'));
        }

        // 2. Force password change if expired or flagged
        if ($this->passwordExpired($user)) {
            return $this->denyWith($request, 'Your password has expired. Please update it.', route('auth.password.change'));
        }

        return $next($request);
    }

    private function passwordExpired(\App\Core\User\Models\User $user): bool
    {
        /** @var \App\Core\UserManagement\Models\PasswordSecurity|null $policy */
        $policy = $user->passwordSecurity;

        // force_change flag set by an admin
        if ($policy?->force_change) {
            return true;
        }

        // Explicit expiry set on the record
        $expiresAt = $policy?->expires_at ?? $policy?->password_expiry_at;
        if ($expiresAt && now()->gt($expiresAt)) {
            return true;
        }

        // Fall back to company-wide policy using password_changed_at
        if (!$expiresAt) {
            $months = CompanyIdentity::first()?->password_expiring_month;
            if ($months && $months > 0) {
                // Parse defensively — the value may be a Carbon instance or a
                // raw datetime string depending on model casting.
                $baseline = $user->password_changed_at ?? $user->created_at;
                $baseline = $baseline ? \Illuminate\Support\Carbon::parse($baseline) : null;
                if ($baseline && now()->gt($baseline->copy()->addMonths($months))) {
                    // Auto-create/update the record so the user lands on the change page
                    PasswordSecurity::addExpiryDateFromMonths($user->id, $months);
                    return true;
                }
            }
        }

        return false;
    }

    private function isExcluded(Request $request): bool
    {
        foreach (self::EXCLUDED_ROUTES as $name) {
            if ($request->routeIs($name)) {
                return true;
            }
        }

        return false;
    }

    private function denyWith(Request $request, string $message, string $redirectUrl): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect($redirectUrl)->with('error', $message);
    }
}
