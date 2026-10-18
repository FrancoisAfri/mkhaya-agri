<?php

namespace App\Http\Middleware;

use App\Modules\HR\LMS\Services\LmsComplianceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureComplianceTraining
{
    public function __construct(private LmsComplianceService $service) {}

    /**
     * Usage in routes: ->middleware('compliance.check:action_key')
     * Returns 403 JSON for API, or Inertia error for web requests.
     */
    public function handle(Request $request, Closure $next, string $actionKey = ''): Response
    {
        $user = $request->user();

        if (!$user || !$actionKey) {
            return $next($request);
        }

        if ($this->service->blockRestrictedActionIfTrainingMissing($user->id, $actionKey)) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json([
                    'message'    => 'You have overdue mandatory training that must be completed before performing this action.',
                    'action_key' => $actionKey,
                    'blocked'    => true,
                ], 403);
            }

            return redirect()->route('lms.my-learning-plan')
                ->with('error', 'You have overdue mandatory training. Please complete it before proceeding.');
        }

        return $next($request);
    }
}
