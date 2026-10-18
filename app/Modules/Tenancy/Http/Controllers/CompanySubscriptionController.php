<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\CompanyModule;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Services\CompanySubscription;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Company self-service subscription management: pick a bundle and/or add
 * individual modules, see the live monthly price, and (via the payments phase)
 * pay. Scoped to the signed-in user's own company.
 */
class CompanySubscriptionController extends Controller
{
    public function __construct(private readonly CompanySubscription $subs) {}

    public function index()
    {
        $company = $this->company();
        abort_unless($company, 404);

        $addonModules = CompanyModule::where('company_id', $company->id)
            ->where('source', 'addon')->pluck('module_code')->values();
        $companyAddons = CompanyAddon::where('company_id', $company->id)
            ->get()->map(fn ($a) => ['code' => $a->addon_code, 'quantity' => (int) $a->quantity])->values();

        return Inertia::render('Tenancy/Subscription', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status, 'trial_ends_at' => $company->trial_ends_at?->format('Y-m-d')],
            'snapshot' => $this->subs->snapshot($company),
            // The Subscription Builder consumes the same catalog shape as the platform admin.
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get()
                ->map(fn (Plan $p) => ['code' => $p->code, 'name' => $p->name, 'price_monthly' => (float) $p->price_monthly,
                    'regular_price_monthly' => $p->regular_price_monthly !== null ? (float) $p->regular_price_monthly : null,
                    'max_users' => $p->max_users, 'ai_token_quota' => $p->ai_token_quota, 'sms_quota' => $p->sms_quota,
                    'storage_gb' => $p->storage_gb, 'all_modules' => $p->includesAllModules()]),
            'moduleCatalog' => BillingModule::where('is_active', true)->where('is_sellable', true)->orderBy('category')->orderBy('sort')->get()
                ->map(fn (BillingModule $m) => ['code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'price' => (float) $m->price_monthly]),
            'addonCatalog' => BillingAddon::active()->orderBy('type')->orderBy('sort')->get()
                ->map(fn ($a) => ['type' => $a->type, 'code' => $a->code, 'name' => $a->name, 'unit_label' => $a->unit_label,
                    'units' => $a->units, 'price' => $a->price_monthly !== null ? (float) $a->price_monthly : null]),
            'composition' => ['plan' => $company->plan, 'modules' => $addonModules, 'addons' => $companyAddons],
        ]);
    }

    /** Recompose the signed-in company's own subscription from the Subscription Builder. */
    public function compose(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'exists:billing_modules,code'],
            'addons' => ['nullable', 'array'],
            'addons.*.code' => ['required', 'string', 'exists:billing_addons,code'],
            'addons.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        $this->subs->setBundle($company, $data['plan']);
        $this->subs->setModuleAddons($company, $data['modules'] ?? []);
        $this->subs->setAddons($company, $data['addons'] ?? []);

        return back()->with('success', 'Subscription updated — R'.number_format($this->subs->recomputeTotal($company), 2).'/mo applies to your next invoice.');
    }

    public function setBundle(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);
        $data = $request->validate(['code' => ['nullable', 'string', 'exists:plans,code']]);
        $this->subs->setBundle($company, $data['code'] ?? null);

        return back()->with('success', 'Bundle updated. New total applies to your next invoice.');
    }

    public function addModule(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);
        $data = $request->validate(['code' => ['required', 'string', 'exists:billing_modules,code']]);
        $this->subs->addModule($company, $data['code']);

        return back()->with('success', 'Module added. New total applies to your next invoice.');
    }

    public function removeModule(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);
        $data = $request->validate(['code' => ['required', 'string']]);
        $this->subs->removeModule($company, $data['code']);

        return back()->with('success', 'Module removed.');
    }

    private function company(): ?Company
    {
        // Resolve the active company (handles a real tenant user AND a platform
        // admin viewing-as/impersonating a company). Falls back to the user's own.
        $company = app(CurrentCompany::class)->get();
        if ($company) {
            return $company;
        }
        $id = Auth::user()->company_id ?? null;

        return $id ? Company::find($id) : null;
    }
}
