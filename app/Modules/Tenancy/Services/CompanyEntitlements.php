<?php

namespace App\Modules\Tenancy\Services;

use App\Core\License\LicenseService;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\CompanyModule;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a tenant is entitled to (Phase D). In the SaaS edition this comes
 * from the company's plan/subscription; in the self-hosted edition it comes from
 * the signed license (App\Core\License). Platform admins (no active company) are
 * unrestricted.
 */
class CompanyEntitlements
{
    public function __construct(
        private CurrentCompany $current,
        private LicenseService $license,
    ) {}

    public function planFor(Company $company): ?Plan
    {
        $code = Subscription::where('company_id', $company->id)->value('plan_code') ?? $company->plan;

        return $code ? Plan::where('code', $code)->first() : null;
    }

    /** Module code_names this company may use; null = all. */
    public function modules(Company $company): ?array
    {
        // If the company has an explicit à-la-carte composition, that governs.
        if (CompanyModule::where('company_id', $company->id)->exists()) {
            return app(CompanySubscription::class)->effectiveModules($company);
        }

        $plan = $this->planFor($company);
        if (! $plan || $plan->includesAllModules()) {
            return null;
        }

        return $plan->modules;
    }

    public function maxUsers(Company $company): ?int
    {
        return $this->planFor($company)?->max_users;
    }

    /**
     * Non-login employee allowance = the number of "employee" add-on units the
     * company has bought (there is no plan base allowance). Self-hosted is not
     * seat-metered, so it is unlimited there.
     */
    public function maxNonLoginEmployees(Company $company): ?int
    {
        if (config('edition.edition') === 'self_hosted') {
            return null; // unlimited
        }

        return (int) CompanyAddon::where('company_id', $company->id)
            ->where('type', 'employee')
            ->sum('quantity');
    }

    /** Active employees without a login account, for the current company. */
    public function nonLoginEmployeeCount(Company $company): int
    {
        return (int) DB::table('hr_people')
            ->where('company_id', $company->id)
            ->whereNull('user_id')
            ->where('status', 1)
            ->count();
    }

    public function currentCanAddNonLoginEmployee(): bool
    {
        $company = $this->current->get();
        if (! $company) {
            return true; // console / standalone with no active company
        }

        $limit = $this->maxNonLoginEmployees($company);
        if ($limit === null) {
            return true; // self-hosted / unlimited
        }

        return $this->nonLoginEmployeeCount($company) < $limit;
    }

    /**
     * What this company is billed each month, in full.
     *
     * The bundle, plus the modules bought à la carte, plus the add-on packs — extra users, AI
     * packs, storage, extra businesses. This is the number `subscription_invoices` is raised for,
     * so anything it leaves out is money the platform never asks for.
     *
     * It used to be the plan's list price alone, which was wrong three ways at once:
     *
     *   Every à-la-carte module was free. A company on a R500 bundle who had added four modules
     *   at R300 each was invoiced R500.
     *   Every add-on pack was free, for the same reason — and that is the whole billing side of
     *   this feature, so an extra trading business would have been sold and never charged for.
     *   Everybody paid the launch price forever. `plans.price_monthly` is the launch price and
     *   `regular_price_monthly` is the one that applies once the launch window closes;
     *   `effectivePrice()` picks between them and honours a founding customer's price lock.
     *   Reading the column directly meant the launch discount never expired for anyone.
     *
     * All three undercharge, which is why nobody reported it.
     *
     * The composition already exists and is already what the subscription screen shows the
     * customer; this now reads the same figure rather than a second, quieter one. A customer being
     * shown one total and invoiced another is the version of this bug that gets noticed.
     */
    public function monthlyPrice(Company $company): float
    {
        return (float) app(CompanySubscription::class)->snapshot($company)['total'];
    }

    /** Does the ACTIVE company (or license, self-hosted) allow this module? */
    public function currentAllowsModule(string $code): bool
    {
        // Self-hosted: the signed license is the gate (existing behaviour).
        if (Edition::isSelfHosted()) {
            return $this->license->allows($code);
        }

        // SaaS: platform admin / console (no company) → unrestricted.
        $company = $this->current->get();
        if (! $company) {
            return true;
        }

        $modules = $this->modules($company);

        return $modules === null || in_array($code, $modules, true);
    }

    /**
     * White-label branding entitlement — may the company set its own in-system logo?
     * Platform-admin-toggled per company in SaaS; always on for self-hosted (single
     * tenant owns its branding). Free feature (no billing).
     */
    public function hasWhiteLabel(?Company $company = null): bool
    {
        if (Edition::isSelfHosted()) {
            return true;
        }

        $company ??= $this->current->get();

        return (bool) ($company->white_label ?? false);
    }

    /** @var array<string>|null memoised set of sellable (gate-able) module codes */
    private ?array $gateable = null;

    /**
     * Should a navigation / access-UI module with this `code_name` be shown?
     * Only sellable feature modules are gated (by plan in SaaS, by licence when
     * self-hosted); core / base-platform UI is always available. Platform admins
     * and unentitled-but-unknown codes are allowed through.
     */
    public function allowsNavModule(?string $code): bool
    {
        if (! $code) {
            return true;
        }
        $this->gateable ??= BillingModule::where('is_sellable', true)->pluck('code')->all();
        if (! in_array($code, $this->gateable, true)) {
            return true; // core / not a sellable module → never hidden
        }

        return $this->currentAllowsModule($code);
    }

    public function currentCanAddUser(): bool
    {
        $company = $this->current->get();
        if (! $company) {
            return true;
        }
        $limit = $this->maxUsers($company);
        if ($limit === null) {
            return true;
        }

        return $company->users()->count() < $limit;
    }
}
