<?php

namespace App\Modules\Agri\Http\Middleware;

use App\Modules\Agri\Api\ApiAbilities;
use App\Modules\Agri\Api\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An endpoint's ability: the token must have been issued with it, and its service user must still
 * hold the farm permissions behind it (an administrator who takes a permission away from the
 * integration's user takes it away from the token too).
 */
class AgriApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        if (! $user?->tokenCan($ability)) {
            return ApiProblem::make(403, 'Forbidden', "This token was not issued with the \"{$ability}\" ability.", $request, ['ability' => $ability], 'missing-ability');
        }
        foreach (ApiAbilities::MAP[$ability] ?? [] as $permission) {
            if (! $user->can($permission)) {
                return ApiProblem::make(403, 'Forbidden', "This API client's user no longer holds {$permission}.", $request, ['ability' => $ability], 'missing-permission');
            }
        }

        return $next($request);
    }
}
