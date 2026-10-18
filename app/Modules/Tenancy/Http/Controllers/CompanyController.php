<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\CompanyModule;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Subscription;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use App\Modules\Tenancy\Services\CompanySubscription;
use App\Modules\Tenancy\Services\StorageQuota;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use App\Modules\Tenancy\Services\CompanyToReseller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform super-admin: manage tenants (Phase F). Not tenant-scoped — the
 * platform admin sees and administers every company.
 */
class CompanyController extends Controller implements HasMiddleware
{
    /**
     * A company converted to a reseller is closed for good: nothing may reactivate, bill or reshape
     * it before its data is purged. Viewing it (View as, Invoices) and paying old invoices stay open.
     */
    public static function middleware(): array
    {
        return [new Middleware(function (Request $request, \Closure $next) {
            $company = $request->route('company');
            if ($company instanceof Company && $company->isArchived()) {
                return back()->with('error', "{$company->name} was converted to a reseller and is archived; it cannot be changed.");
            }

            return $next($request);
        }, only: ['resetAdminPassword', 'setPlan', 'compose', 'suspend', 'activate', 'extendTrial', 'setWhiteLabel', 'update', 'convertToReseller'])];
    }

    public function index(): Response
    {
        $userCounts = DB::table('users')->select('company_id', DB::raw('count(*) c'))->groupBy('company_id')->pluck('c', 'company_id');
        $subs = Subscription::pluck('plan_code', 'company_id');
        $plans = Plan::orderBy('sort')->get()->keyBy('code');

        // Per-company à-la-carte composition (module add-ons + non-module add-ons).
        $moduleAddons = CompanyModule::where('source', 'addon')
            ->get()->groupBy('company_id');
        $companyAddons = CompanyAddon::all()->groupBy('company_id');

        // Real storage used per company = uploaded files + relational data footprint
        // (platform admin has no RLS scope here, so this aggregates every tenant).
        $storageFiles = DB::table('tenant_files')->select('company_id', DB::raw('sum(size) bytes'))
            ->groupBy('company_id')->pluck('bytes', 'company_id');
        $storageData = DB::table('company_storage_stats')->pluck('data_bytes', 'company_id');
        $storageQuota = app(StorageQuota::class);

        // Catalog lookups so MRR + seat counts can fold in add-ons/modules (mirror
        // of the Subscription Builder total — base + à-la-carte modules + add-ons).
        $addonUnits = BillingAddon::pluck('units', 'code');
        $modulePrices = BillingModule::pluck('price_monthly', 'code');

        $resellers = \App\Modules\Tenancy\Models\Reseller::query()->whereIn('id', Company::query()->whereNotNull('converted_to_reseller_id')->pluck('converted_to_reseller_id'))->pluck('uuid', 'id');

        $companies = Company::orderByDesc('id')->get()->map(function (Company $c) use ($resellers, $userCounts, $subs, $plans, $moduleAddons, $companyAddons, $storageFiles, $storageData, $storageQuota, $addonUnits, $modulePrices) {
            $planCode = $subs[$c->id] ?? $c->plan;
            $plan = $plans[$planCode] ?? null;

            $cAddons = $companyAddons[$c->id] ?? collect();
            $cModules = $moduleAddons[$c->id] ?? collect();

            // Effective monthly revenue = base plan + à-la-carte module add-ons + add-ons.
            $base = $plan ? (float) $plan->effectivePrice($c) : 0.0;
            $addonsPrice = (float) $cAddons->sum(fn ($a) => (float) $a->price_monthly * max(1, (int) $a->quantity));
            $modulesPrice = ($plan && $plan->includesAllModules())
                ? 0.0
                : (float) $cModules->sum(fn ($m) => (float) ($modulePrices[$m->module_code] ?? 0));
            $mrr = round($base + $addonsPrice + $modulesPrice, 2);

            // Effective seat cap = plan seats + extra-user add-ons (quantity × units).
            $extraSeats = (int) $cAddons->where('type', 'user')
                ->sum(fn ($a) => (int) $a->quantity * max(1, (int) ($addonUnits[$a->addon_code] ?? 1)));
            $maxUsers = ($plan && $plan->max_users) ? ((int) $plan->max_users + $extraSeats) : null;

            return [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'status' => $c->status,
                'plan' => $planCode, 'plan_name' => $plan->name ?? $planCode,
                'users' => (int) ($userCounts[$c->id] ?? 0), 'max_users' => $maxUsers,
                'mrr' => $mrr,
                'founding_customer' => (bool) $c->founding_customer,
                'white_label' => (bool) $c->white_label,
                'trial_ends_at' => $c->trial_ends_at?->toDateString(), 'contact_email' => $c->contact_email,
                // Trial state, so the list shows a lock-out coming (or already happened)
                // instead of only a date the reader has to compare against today.
                'trial_expired' => $c->trialExpired() || ($c->suspended_reason === 'Trial expired'),
                'trial_days_left' => ($c->status === 'trial' && $c->trial_ends_at)
                    ? (int) ceil(now()->floatDiffInDays($c->trial_ends_at, false))
                    : null,
                'suspended_reason' => $c->suspended_reason,
                'archived_at' => $c->archived_at?->toDateString(),
                'purge_after' => $c->purge_after?->toDateString(),
                'purged' => $c->purged_at !== null,
                'reseller_uuid' => $c->converted_to_reseller_id ? ($resellers[$c->converted_to_reseller_id] ?? null) : null,
                'contact_person' => $c->contact_person, 'contact_phone' => $c->contact_phone,
                'registration_number' => $c->registration_number, 'vat_number' => $c->vat_number,
                'storage_used' => (int) ($storageFiles[$c->id] ?? 0) + (int) ($storageData[$c->id] ?? 0),
                'storage_quota' => (int) $storageQuota->quotaBytes($c), // 0 = unlimited
                // Current composition, for pre-loading the builder when editing.
                'addon_modules' => ($moduleAddons[$c->id] ?? collect())->pluck('module_code')->values(),
                'addons' => ($companyAddons[$c->id] ?? collect())->map(fn ($a) => ['code' => $a->addon_code, 'quantity' => (int) $a->quantity])->values(),
            ];
        });

        return Inertia::render('Tenancy/Companies', [
            'companies' => $companies,
            'plans' => $plans->where('is_active', true)->values()->map(fn ($p) => [
                'code' => $p->code, 'name' => $p->name, 'price_monthly' => (float) $p->price_monthly,
                'regular_price_monthly' => $p->regular_price_monthly !== null ? (float) $p->regular_price_monthly : null,
                'max_users' => $p->max_users, 'ai_token_quota' => $p->ai_token_quota, 'sms_quota' => $p->sms_quota,
                'storage_gb' => $p->storage_gb, 'all_modules' => $p->includesAllModules(),
            ]),
            'moduleCatalog' => BillingModule::where('is_active', true)->where('is_sellable', true)
                ->orderBy('category')->orderBy('sort')->get()
                ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'price' => (float) $m->price_monthly]),
            'addonCatalog' => BillingAddon::active()->orderBy('type')->orderBy('sort')->get()
                ->map(fn ($a) => ['type' => $a->type, 'code' => $a->code, 'name' => $a->name, 'unit_label' => $a->unit_label,
                    'units' => $a->units, 'price' => $a->price_monthly !== null ? (float) $a->price_monthly : null]),
            'summary' => [
                'total' => $companies->count(),
                'active' => $companies->where('status', 'active')->count(),
                'trial' => $companies->where('status', 'trial')->count(),
                'suspended' => $companies->where('status', 'suspended')->count(),
                'archived' => $companies->where('status', 'archived')->count(),
                'mrr' => round($companies->whereIn('status', ['active', 'trial'])->sum('mrr'), 2),
            ],
            'impersonating' => session('impersonate_company_id'),
        ]);
    }

    public function store(Request $request, CompanyProvisioner $provisioner, CompanySubscription $subs): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'registration_number' => 'nullable|string|max:60',
            'admin_name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
            'plan' => 'required|string|exists:plans,code',
            'trial_days' => 'nullable|integer|in:0,7,14,30',
            'modules' => 'nullable|array',
            'modules.*' => 'string|exists:billing_modules,code',
            'addons' => 'nullable|array',
            'addons.*.code' => 'required|string|exists:billing_addons,code',
            'addons.*.quantity' => 'required|integer|min:0',
        ]);
        $result = $provisioner->provision($data);

        // Apply the Subscription Builder composition on top of the base plan.
        $company = $result['company'];
        $subs->setModuleAddons($company, $data['modules'] ?? []);
        $subs->setAddons($company, $data['addons'] ?? []);

        $trialDays = (int) ($data['trial_days'] ?? config('edition.trial_days', 30));
        $trialLine = $trialDays > 0 ? "on a {$trialDays}-day trial" : 'active from day one';
        $message = "Company created {$trialLine} — R".number_format($subs->recomputeTotal($company), 2)
            ."/mo. The admin ({$data['email']}) can sign in and set up their workspace.";

        // Email the login details to the new admin and surface them to the platform
        // admin (for copying / manual delivery if the email didn't go through).
        $emailed = $this->emailCredentials($data['admin_name'], $data['email'], $data['password']);

        return back()->with('success', $message)->with('credentials', [
            'title' => 'Admin login details',
            'name' => $data['admin_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'login_url' => rtrim(config('app.url'), '/').'/login',
            'emailed' => $emailed,
        ]);
    }

    /**
     * Reset the company admin's password to a freshly generated one, email it, and
     * return it so the platform admin can copy it (e.g. if email delivery fails).
     */
    public function resetAdminPassword(Company $company): RedirectResponse
    {
        $user = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when($company->contact_email, fn ($q) => $q->where('email', $company->contact_email))
            ->orderBy('id')
            ->first()
            ?? User::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->first();

        abort_unless($user, 404, 'No admin user found for this company.');

        $password = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => now(),
        ])->save();

        $name = $company->contact_person ?: ($company->name ?: 'Admin');
        $emailed = $this->emailCredentials($name, $user->email, $password);

        return back()->with('success', "Password reset for {$user->email}.")->with('credentials', [
            'title' => 'New admin password',
            'name' => $name,
            'email' => $user->email,
            'password' => $password,
            'login_url' => rtrim(config('app.url'), '/').'/login',
            'emailed' => $emailed,
        ]);
    }

    /** Email login credentials to a user. Returns whether delivery succeeded. */
    private function emailCredentials(string $name, string $email, string $password): bool
    {
        try {
            Mail::to($email)->send(new UserWelcomeMail(
                name: $name,
                email: $email,
                password: $password,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Company admin credential email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function setPlan(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['plan' => 'required|string|exists:plans,code']);
        $company->update(['plan' => $data['plan']]);
        Subscription::updateOrCreate(['company_id' => $company->id], ['plan_code' => $data['plan']]);

        return back()->with('success', 'Plan updated.');
    }

    /** Recompose an existing company's subscription from the Subscription Builder. */
    public function compose(Request $request, Company $company, CompanySubscription $subs): RedirectResponse
    {
        $data = $request->validate([
            'plan' => 'required|string|exists:plans,code',
            'modules' => 'nullable|array',
            'modules.*' => 'string|exists:billing_modules,code',
            'addons' => 'nullable|array',
            'addons.*.code' => 'required|string|exists:billing_addons,code',
            'addons.*.quantity' => 'required|integer|min:0',
        ]);

        $subs->setBundle($company, $data['plan']);                 // also syncs bundle modules + total
        Subscription::updateOrCreate(['company_id' => $company->id], ['plan_code' => $data['plan']]);
        $subs->setModuleAddons($company, $data['modules'] ?? []);
        $subs->setAddons($company, $data['addons'] ?? []);

        return back()->with('success', 'Subscription updated — R'.number_format($subs->recomputeTotal($company), 2).'/mo.');
    }

    public function suspend(Company $company): RedirectResponse
    {
        $company->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => 'Suspended by platform admin']);

        return back()->with('success', 'Company suspended.');
    }

    /**
     * Put a company on a full subscription. Also the way out of a trial: the trial
     * end date is cleared, because a company left on status "trial" with a past
     * trial_ends_at is blocked by ResolveCompany even while it looks fine here.
     */
    public function activate(Company $company): RedirectResponse
    {
        $company->update([
            'status' => 'active',
            'trial_ends_at' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);

        return back()->with('success', "{$company->name} is now active — the subscription is live and everyone can sign in.");
    }

    /**
     * Give a company more trial time. Works both before the trial runs out and after
     * it has already locked the company out (whether it is still status "trial" with a
     * past end date, or was suspended by tenancy:expire-trials) — the previous only way
     * back in was to suspend and then activate, which quietly converted a trial into a
     * paying subscription.
     */
    public function extendTrial(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required_without:trial_ends_at', 'nullable', 'integer', 'min:1', 'max:365'],
            'trial_ends_at' => ['required_without:days', 'nullable', 'date'],
        ]);

        if (! empty($data['trial_ends_at'])) {
            $endsAt = Carbon::parse($data['trial_ends_at'])->endOfDay();

            if ($endsAt->isPast()) {
                return back()->withErrors(['trial_ends_at' => 'Pick a date in the future — that trial end date has already passed.']);
            }
        } else {
            // Extend from the existing end date when the trial is still running, so no
            // days are lost; from today when it has already lapsed.
            $from = ($company->trial_ends_at && $company->trial_ends_at->isFuture())
                ? $company->trial_ends_at->copy()
                : Carbon::now();
            $endsAt = $from->addDays((int) $data['days'])->endOfDay();
        }

        $company->update([
            'status' => 'trial',
            'trial_ends_at' => $endsAt,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);

        return back()->with('success', "{$company->name}'s trial now runs to {$endsAt->toFormattedDateString()}.");
    }

    /** Toggle white-label branding — lets the company set its own in-system logo. */
    public function setWhiteLabel(Request $request, Company $company): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $company->update(['white_label' => $enabled]);

        return back()->with('success', $enabled
            ? 'White-label branding enabled — the company can now set its own logo.'
            : 'White-label branding disabled.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $company->update($data);

        return redirect()->route('tenancy.companies.index')->with('success', 'Company updated.');
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        // The primary/master company is not deletable.
        abort_if((int) $company->id === 1, 422, 'The primary company cannot be deleted.');

        // If we're impersonating the company being deleted, drop that first.
        if ((int) $request->session()->get('impersonate_company_id') === (int) $company->id) {
            $request->session()->forget('impersonate_company_id');
        }

        $cid = (int) $company->id;
        $name = $company->name;

        DB::transaction(function () use ($cid, $company) {
            // company_id carries no cross-table FK constraints, so every tenant
            // table's rows for this company can be purged in any order for a true
            // clean slate (users included), then the company row itself.
            $tables = DB::select(
                "SELECT c.table_name FROM information_schema.columns c
                 JOIN information_schema.tables t
                   ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                 WHERE c.column_name = 'company_id'
                   AND c.table_schema = 'public'
                   AND t.table_type = 'BASE TABLE'"
            );

            foreach ($tables as $t) {
                DB::table($t->table_name)->where('company_id', $cid)->delete();
            }

            $company->delete();
        });

        return redirect()->route('tenancy.companies.index')
            ->with('success', "Company \"{$name}\" and all its data were deleted.");
    }

    /** Who would move with the company, and the role each is offered (for the conversion dialog). */
    public function conversionCandidates(Company $company, CompanyToReseller $convert): JsonResponse
    {
        return response()->json([
            'users' => $convert->candidates($company),
            'retention_years' => CompanyToReseller::DEFAULT_RETENTION_YEARS,
            'open_invoices' => SubscriptionInvoice::query()->where('company_id', $company->id)->whereIn('status', ['open', 'overdue'])->count(),
        ]);
    }

    /** Convert a tenant into a reseller: the company is archived and its chosen users move to the reseller portal. */
    public function convertToReseller(Request $request, Company $company, CompanyToReseller $convert): RedirectResponse
    {
        $data = $request->validate([
            'commission_type' => ['required', 'in:percent,fixed'],
            'commission_value' => ['required', 'numeric', 'min:0', $request->input('commission_type') === 'percent' ? 'max:100' : 'max:1000000'],
            'retention_years' => ['required', 'integer', 'min:1', 'max:10'],
            'users' => ['required', 'array', 'min:1'],
            'users.*' => ['required', 'in:owner,staff,exclude'],
            'confirm_name' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (mb_strtolower(trim($data['confirm_name'])) !== mb_strtolower(trim($company->name))) {
            return back()->withErrors(['confirm_name' => 'Type the company name exactly to confirm.']);
        }

        try {
            $reseller = $convert->convert($company, $data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }
        if ((int) $request->session()->get('impersonate_company_id') === (int) $company->id) {
            $request->session()->forget('impersonate_company_id');
        }

        return redirect()->route('tenancy.resellers.show', $reseller->uuid)->with('success',
            "{$company->name} is now a reseller. Its people sign in to the reseller portal with their existing logins; the old company's data is kept until {$company->refresh()->purge_after->toFormattedDateString()} and then deleted.");
    }

    public function impersonate(Request $request, Company $company): \Symfony\Component\HttpFoundation\Response
    {
        $request->session()->put('impersonate_company_id', $company->id);
        $request->session()->flash('success', "Now viewing {$company->name} as an impersonated tenant.");

        // Switching tenant context changes all shared state (nav, entitlements,
        // scoped data). Force a full browser reload rather than an Inertia XHR
        // follow — the latter can land on a stale/404 partial until manually
        // reloaded.
        return Inertia::location(route('dashboard'));
    }

    public function stopImpersonating(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $request->session()->forget('impersonate_company_id');
        $request->session()->flash('success', 'Stopped impersonating.');

        return Inertia::location(route('tenancy.companies.index'));
    }

    public function invoices(Company $company, SubscriptionBilling $billing): Response
    {
        return Inertia::render('Tenancy/CompanyInvoices', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'invoices' => SubscriptionInvoice::where('company_id', $company->id)->orderByDesc('id')->get()
                ->map(fn (SubscriptionInvoice $i) => ['id' => $i->id, 'period' => $i->period, 'amount' => (float) $i->amount,
                    'status' => $i->status, 'due_date' => $i->due_date?->toDateString(), 'paid_at' => $i->paid_at?->toDateString()]),
        ]);
    }

    public function markPaid(Request $request, SubscriptionInvoice $invoice, SubscriptionBilling $billing): RedirectResponse
    {
        $billing->markPaid($invoice, $request->input('reference'), 'manual');

        return back()->with('success', 'Invoice marked paid.');
    }
}
