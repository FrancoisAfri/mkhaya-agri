<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class AuthLock
{
    /**
     * Session key for last activity timestamp.
     */
    protected const LAST_ACTIVITY_KEY = 'last_activity';

    /**
     * Session key that flags the session as locked.
     */
    public const SESSION_LOCKED_KEY = 'session_locked';

    /**
     * Routes that are always allowed through, even when locked.
     * Add your lock/unlock route names here.
     */
    protected array $bypassRoutes = [
        'lock.screen',
        'lock.session',
        'login.unlock',
        'auth.logout',
        'logout',
    ];

    /**
     * Inactivity timeout in minutes.
     */
    protected int $timeout;

    public function __construct()
    {
        $this->timeout = (int) config('auth.inactivity_timeout', 15);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): mixed
    {
        $authGuard = $guard ? Auth::guard($guard) : Auth::guard();

        // Only apply logic to authenticated users
        if (! $authGuard->check()) {
            return $next($request);
        }

        // Always let lock-related routes through
        if ($this->isBypassRoute($request)) {
            return $next($request);
        }

        $currentTimestamp = now()->timestamp;
        $lastActivity     = session(self::LAST_ACTIVITY_KEY);
        $isLocked         = session(self::SESSION_LOCKED_KEY, false);

        // ── Already locked ──────────────────────────────────────────────
        // Session was previously flagged as locked (e.g. user navigated
        // away and came back). Keep enforcing the lock screen.
        if ($isLocked) {
            return $this->respondLocked($request);
        }

        // ── Inactivity check ────────────────────────────────────────────
        if ($lastActivity && ($currentTimestamp - $lastActivity) > $this->getTimeoutInSeconds()) {
            return $this->lockSession($request, $authGuard->user());
        }

        // ── Active session — refresh timestamp ──────────────────────────
        session([self::LAST_ACTIVITY_KEY => $currentTimestamp]);

        return $next($request);
    }

    // ────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Lock the session without logging the user out.
     * The user identity is preserved so the lock screen can show
     * their name/avatar and the unlock flow can re-verify their password.
     */
    protected function lockSession(Request $request, mixed $user): mixed
    {
        // Flag the session as locked
        session([self::SESSION_LOCKED_KEY => true]);

        // Clear the activity timer so it doesn't keep triggering
        session()->forget(self::LAST_ACTIVITY_KEY);

        // Fire event — useful for audit logging
        Event::dispatch('auth.inactivity.lock', $user);

        return $this->respondLocked($request);
    }

    /**
     * Return the appropriate locked response for JSON or web requests.
     */
    protected function respondLocked(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Your session has been locked due to inactivity. Please unlock to continue.'),
                'locked'  => true,
            ], 423); // 423 Locked
        }

        return redirect()->route('lock.screen');
    }

    /**
     * Check whether the current request targets a bypass route.
     */
    protected function isBypassRoute(Request $request): bool
    {
        $currentRouteName = $request->route()?->getName();

        return in_array($currentRouteName, $this->bypassRoutes, true);
    }

    /**
     * Convert timeout minutes to seconds.
     */
    protected function getTimeoutInSeconds(): int
    {
        return $this->timeout * 60;
    }
}
