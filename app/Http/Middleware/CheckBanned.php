<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * Checks if the authenticated user's status is disabled (0 or false).
     * If disabled, automatically logs them out and prevents further access.
     * Supports both web and API responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return $next($request);
        }

        $user = auth()->user();

        // Check if user status is disabled (0 or false)
        if ((int) $user->status === 0) {
            // Log the security event
            Log::warning('User account disabled. Auto-logout triggered.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'timestamp' => now(),
            ]);

            // Logout the user
            Auth::logout();

            // Invalidate the session
            $request->session()->invalidate();

            // Regenerate token for CSRF protection
            $request->session()->regenerateToken();

            // Return appropriate response based on request type
            if ($request->expectsJson() || $request->is('api/*')) {
                // API response
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Your account has been disabled. Please contact the administrator for assistance.',
                        'error' => 'ACCOUNT_DISABLED'
                    ],
                    403
                );
            }

            // Web response - redirect to login
            return redirect()->route('login')->with(
                'error',
                'Your account has been disabled. Please contact the administrator for assistance.'
            );
        }

        return $next($request);
    }
}
