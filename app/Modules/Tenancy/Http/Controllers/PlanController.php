<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Super-admin management of subscription plans (module bundles + user caps).
 * Mirrors mkhaya-prop's PlanController, adapted to hub's plan shape (no unit /
 * AI / SMS quotas — hub gates on modules + user count).
 */
class PlanController extends Controller
{
    /** Core modules every plan includes — not gated by a module.active route. */
    private const CORE_CODES = [
        'user_management' => 'User Management',
        'roles_permissions' => 'Roles & Permissions',
        'reports' => 'Reports',
        'company_settings' => 'Company Settings',
        'system_settings' => 'System Settings',
    ];

    public function index()
    {
        return Inertia::render('Tenancy/Plans', [
            'plans' => Plan::orderBy('sort')->get(),
            'moduleCatalog' => BillingModule::where('is_active', true)
                ->orderBy('category')->orderBy('sort')->get()
                ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'price' => (float) $m->price_monthly, 'sellable' => $m->is_sellable]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = Str::slug($data['name']).'-'.Str::lower(Str::random(3));
        Plan::create($data);

        return back()->with('success', 'Plan created.');
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request));
        Cache::forget("plan_{$plan->code}");

        return back()->with('success', 'Plan updated.');
    }

    public function destroy(Plan $plan)
    {
        if (Company::where('plan', $plan->code)->exists()) {
            return back()->with('error', 'Cannot delete a plan that companies are subscribed to.');
        }
        $plan->delete();

        return back()->with('success', 'Plan removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_users' => ['nullable', 'integer', 'min:0'],   // null = unlimited
            'ai_token_quota' => ['nullable', 'integer', 'min:0'],   // 0 = unlimited
            'sms_quota' => ['nullable', 'integer', 'min:0'],   // 0 = unlimited
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],   // launch price
            'regular_price_monthly' => ['nullable', 'numeric', 'min:0'], // post-launch price
            'storage_gb' => ['nullable', 'integer', 'min:0'],
            'support' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer'],
        ]);

        // Empty selection = all modules (stored as null, matching the enterprise plan).
        $data['modules'] = empty($data['modules']) ? null : array_values($data['modules']);

        return $data;
    }

    /**
     * Every gate-able module code: the `module.active:<code>` params declared on
     * routes, unioned with the always-on core codes. Names are humanised.
     */
    private function moduleCatalog(): array
    {
        $codes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'module.active:')) {
                    foreach (explode(',', Str::after($mw, 'module.active:')) as $c) {
                        $codes[trim($c)] = true;
                    }
                }
            }
        }

        $catalog = [];
        foreach (array_keys($codes) as $code) {
            $catalog[$code] = ['code' => $code, 'name' => Str::of($code)->replace('_', ' ')->title()->toString()];
        }
        foreach (self::CORE_CODES as $code => $name) {
            $catalog[$code] = ['code' => $code, 'name' => $name];
        }

        ksort($catalog);

        return array_values($catalog);
    }
}
