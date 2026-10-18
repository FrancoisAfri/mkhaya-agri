<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated or locked account's tokens stop working at once (WMS-202). The platform's
 * `check.banned` ends web sessions, but calls logout(), which a token guard does not have.
 */
class RefuseInactiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ((int) $user->status === 0 || (method_exists($user, 'isLocked') && $user->isLocked()))) {
            return response()->json(['message' => 'This account is not active.'], 403);
        }

        return $next($request);
    }
}
