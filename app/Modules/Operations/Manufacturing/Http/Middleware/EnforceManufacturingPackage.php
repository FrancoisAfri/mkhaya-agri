<?php

namespace App\Modules\Operations\Manufacturing\Http\Middleware;

use App\Modules\Operations\Manufacturing\Services\ManufacturingPackage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFG-025: an area outside the company's manufacturing package is refused however it is reached --
 * menu, typed URL or direct API call -- and while the account has an overdue invoice nothing in
 * manufacturing can be changed (reading and exporting go on). Role permissions are checked
 * separately, by each action's policy: a package never grants what a role does not.
 */
class EnforceManufacturingPackage
{
    public function __construct(private readonly ManufacturingPackage $package) {}

    public function handle(Request $request, Closure $next): Response
    {
        $segments = $request->segments();
        $feature = ($segments[0] ?? null) === 'manufacturing' ? ($segments[1] ?? null) : null;

        if ($feature && ! $this->package->allowsFeature($feature)) {
            return $this->refuse($request, sprintf('%s is not included in your %s manufacturing package.',
                ucfirst($feature), $this->package->definition()['label']));
        }

        if (! $request->isMethodSafe() && $this->package->readOnly()) {
            return $this->refuse($request, 'Manufacturing is read-only while your account has an overdue invoice. Nothing has been deleted; everything can still be viewed and exported.');
        }

        return $next($request);
    }

    private function refuse(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            abort(403, $message);
        }

        return redirect()->route('manufacturing.dashboard')->with('error', $message);
    }
}
