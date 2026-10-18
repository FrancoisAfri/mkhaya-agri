<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && $user->two_factor_secret && ! session('2fa_verified')) {
            // Évite la boucle de redirection
            if (! $request->routeIs('2fa.verify') && ! $request->routeIs('2fa.check')) {
                return redirect()->route('2fa.verify');
            }
        }

        return $next($request);
    }
}
