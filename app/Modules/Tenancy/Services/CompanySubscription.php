<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\CompanyModule;
use App\Modules\Tenancy\Models\Plan;

/**
 * A company's effective subscription = an optional bundle (plan) plus à-la-carte
 * module add-ons. The effective module set gates access (see CompanyEntitlements);
 * the monthly total = bundle price + sum of add-on prices. `company_modules` holds
 * the priced composition (source = bundle | addon).
 */
class CompanySubscription
{
    /** Always-included core module codes. */
    private function coreCodes(): array
    {
        return BillingModule::where('is_sellable', false)->pluck('code')->all();
    }

    public function planFor(Company $company): ?Plan
    {
        return $company->plan ? Plan::where('code', $company->plan)->first() : null;
    }

    /** Effective module codes the company may use (bundle ∪ add-ons ∪ core). */
    public function effectiveModules(Company $company): array
    {
        $plan = $this->planFor($company);
        // Enterprise / "all modules" bundle → everything sellable + core.
        if ($plan && $plan->includesAllModules()) {
            return BillingModule::pluck('code')->all();
        }

        $codes = $this->coreCodes();
        if ($plan && is_array($plan->modules)) {
            $codes = array_merge($codes, $plan->modules);
        }
        $codes = array_merge($codes, CompanyModule::where('company_id', $company->id)->pluck('module_code')->all());

        return array_values(array_unique($codes));
    }

    public function snapshot(Company $company): array
    {
        $plan = $this->planFor($company);
        // Founding customers keep the launch price until their lock expires.
        $bundlePrice = $plan ? $plan->effectivePrice($company) : 0.0;
        $allModules = $plan?->includesAllModules() ?? false;

        $addons = CompanyModule::where('company_id', $company->id)->where('source', 'addon')->get();
        $addonsPrice = (float) $addons->sum('price_monthly');

        // Non-module add-ons (extra users / AI packs / storage packs).
        $extra = CompanyAddon::where('company_id', $company->id)->get();
        $extraPrice = (float) $extra->sum(fn (CompanyAddon $a) => $a->lineTotal());

        return [
            'bundle' => $plan ? ['code' => $plan->code, 'name' => $plan->name, 'price' => $bundlePrice, 'all_modules' => $allModules, 'modules' => $plan->modules ?? []] : null,
            'addons' => $addons->map(fn ($a) => ['code' => $a->module_code, 'price' => (float) $a->price_monthly])->values(),
            'addons_price' => $addonsPrice,
            'extra' => $extra->map(fn (CompanyAddon $a) => ['code' => $a->addon_code, 'type' => $a->type, 'quantity' => (int) $a->quantity, 'unit_price' => (float) $a->price_monthly, 'line' => $a->lineTotal()])->values(),
            'extra_price' => $extraPrice,
            'total' => $bundlePrice + $addonsPrice + $extraPrice,
            'modules' => $this->effectiveModules($company),
        ];
    }

    /**
     * Replace the company's non-module add-ons with the given set. Each item is
     * ['code' => string, 'quantity' => int]; quantity 0 removes it. Per-unit price
     * is snapshotted from the catalog.
     */
    public function setAddons(Company $company, array $items): void
    {
        $catalog = BillingAddon::active()->get()->keyBy('code');
        $keep = [];
        foreach ($items as $item) {
            $code = $item['code'] ?? null;
            $qty = (int) ($item['quantity'] ?? 0);
            $addon = $code ? $catalog->get($code) : null;
            if (! $addon || $qty < 1 || $addon->price_monthly === null) {
                continue; // unknown, empty, or quote-only add-on
            }
            CompanyAddon::updateOrCreate(
                ['company_id' => $company->id, 'addon_code' => $code],
                ['type' => $addon->type, 'quantity' => $qty, 'price_monthly' => $addon->price_monthly],
            );
            $keep[] = $code;
        }
        CompanyAddon::where('company_id', $company->id)->whereNotIn('addon_code', $keep ?: ['__none__'])->delete();
        $this->recomputeTotal($company);
    }

    /** Set (or clear) the company's bundle and re-sync the bundle module rows. */
    public function setBundle(Company $company, ?string $planCode): void
    {
        $company->plan = $planCode;
        $company->save();

        CompanyModule::where('company_id', $company->id)->where('source', 'bundle')->delete();

        $plan = $planCode ? Plan::where('code', $planCode)->first() : null;
        if ($plan && is_array($plan->modules)) {
            foreach ($plan->modules as $code) {
                CompanyModule::updateOrCreate(
                    ['company_id' => $company->id, 'module_code' => $code],
                    ['source' => 'bundle', 'price_monthly' => 0]
                );
            }
        }
        $this->recomputeTotal($company);
    }

    /** Add an à-la-carte module (snapshots its current catalog price). */
    public function addModule(Company $company, string $code): void
    {
        $mod = BillingModule::where('code', $code)->where('is_sellable', true)->first();
        if (! $mod) {
            return;
        }
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_code' => $code],
            ['source' => 'addon', 'price_monthly' => $mod->price_monthly]
        );
        $this->recomputeTotal($company);
    }

    public function removeModule(Company $company, string $code): void
    {
        CompanyModule::where('company_id', $company->id)->where('module_code', $code)->where('source', 'addon')->delete();
        $this->recomputeTotal($company);
    }

    /**
     * Replace the company's à-la-carte module set with exactly $codes (bundle and
     * core modules are untouched). Used by the Subscription Builder.
     */
    public function setModuleAddons(Company $company, array $codes): void
    {
        // An all-inclusive plan already grants every module — never bill add-ons on top.
        if ($this->planFor($company)?->includesAllModules()) {
            $codes = [];
        }
        $sellable = BillingModule::where('is_sellable', true)->whereIn('code', $codes)->get();
        $keep = [];
        foreach ($sellable as $mod) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->id, 'module_code' => $mod->code],
                ['source' => 'addon', 'price_monthly' => $mod->price_monthly],
            );
            $keep[] = $mod->code;
        }
        CompanyModule::where('company_id', $company->id)->where('source', 'addon')
            ->whereNotIn('module_code', $keep ?: ['__none__'])->delete();
        $this->recomputeTotal($company);
    }

    public function recomputeTotal(Company $company): float
    {
        $snap = $this->snapshot($company);
        $company->monthly_total = $snap['total'];
        $company->save();

        return $snap['total'];
    }

    /** Seed company_modules from the company's plan (used at provisioning time). */
    public function syncFromPlan(Company $company): void
    {
        $this->setBundle($company, $company->plan);
    }
}
