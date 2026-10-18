<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckPageUsage
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pageKey     = 'page_' . sha1($request->getPathInfo());
        $lockKey     = 'lock_' . $pageKey;
        $currentUser = Auth::id() ?? 0;

        // Acquire an atomic lock to prevent race conditions when checking / setting page usage
        $lock = Cache::lock($lockKey, 10);

        if ($lock->get()) {
            try {
                if (Cache::has($pageKey)) {
                    $oldUserId = Cache::get($pageKey);

                    if ($oldUserId !== $currentUser) {
                        $otherUser = \App\Core\User\Models\User::find($oldUserId);

                        return response()->json([
                            'message' => 'This page is currently in use by another user.',
                            'other_user' => $otherUser?->only(['id', 'name']),
                            'page_key' => $pageKey,
                        ], 423);
                    }
                }

                // Refresh the ownership TTL
                Cache::put($pageKey, $currentUser, now()->addMinutes(5));
            } finally {
                $lock->release();
            }
        }

        return $next($request);
    }
}
