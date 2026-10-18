<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Support\SoleCompany;
use App\Modules\Tenancy\Services\Edition;

/**
 * How many businesses this tenant may keep books for.
 *
 * The same shape Content Marketing uses for websites, and for the same reason: the plan includes
 * a number, the tenant can buy more, and what they may do is the sum. One place decides it so the
 * screen that shows "2 of 3 used" and the request that refuses the fourth can never disagree.
 *
 * ── Included, then bought ────────────────────────────────────────────────────
 *
 *   allowance = billing_modules.entities_included  +  Σ (company_addons.quantity × billing_addons.units)
 *
 * The included figure is one, not zero, when the accounting module has not been priced yet. A
 * tenant who is paying for Accounting can always keep one set of books; charging for the first
 * entity would be charging twice for the module itself.
 */
class EntityAllowanceService
{
    /** The add-on type that sells extra entities. */
    public const ADDON_TYPE = 'entities';

    public function __construct(private readonly CurrentCompany $company)
    {
    }

    /** @return array{allowance:int, used:int, remaining:int, included:int, purchased:int} */
    public function summary(?int $companyId = null): array
    {
        $companyId = $companyId ?? $this->companyId();

        $included  = $this->included();
        $purchased = $this->purchased($companyId);
        $allowance = $included + $purchased;

        $used = $companyId === null ? 0 : FinEntity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        return [
            'included'  => $included,
            'purchased' => $purchased,
            'allowance' => $allowance,
            'used'      => $used,
            'remaining' => max(0, $allowance - $used),
        ];
    }

    /** Whether one more business can be added right now. */
    public function canAddAnother(?int $companyId = null): bool
    {
        return $this->summary($companyId)['remaining'] > 0;
    }

    /** Add-on packages that sell entities, for the "buy another" offer on the screen. */
    public function packages(): array
    {
        return BillingAddon::query()
            ->where('type', self::ADDON_TYPE)->where('is_active', true)->orderBy('sort')->get()
            ->map(fn (BillingAddon $a) => [
                'code'          => $a->code,
                'name'          => $a->name,
                'units'         => (int) $a->units,
                'unit_label'    => $a->unit_label,
                'price_monthly' => (float) $a->price_monthly,
            ])->all();
    }

    /**
     * What the plan itself covers.
     *
     * `entities_included` is nullable on purpose: a NULL is "nobody has decided yet", and the
     * answer to that is one business, not none. Zero, set deliberately, still means zero.
     */
    private function included(): int
    {
        $module = BillingModule::query()->where('code', 'accounting')->first();

        $included = $module?->entities_included;

        return $included === null ? 1 : max(0, (int) $included);
    }

    /** Extra businesses this tenant has bought. */
    private function purchased(?int $companyId): int
    {
        if ($companyId === null) {
            return 0;
        }

        $units = BillingAddon::query()->where('type', self::ADDON_TYPE)->pluck('units', 'code');

        return (int) CompanyAddon::query()
            ->where('company_id', $companyId)
            ->where('type', self::ADDON_TYPE)
            ->get()
            ->sum(fn (CompanyAddon $a) => (int) $a->quantity * (int) ($units[$a->addon_code] ?? 0));
    }

    /** Whose allowance this is; the sole company on a single-tenant install. See CurrentEntity. */
    private function companyId(): ?int
    {
        $companyId = $this->company->id() ?? auth()->user()?->company_id;

        if (! $companyId && Edition::isSelfHosted()) {
            $companyId = SoleCompany::id();
        }

        return $companyId ? (int) $companyId : null;
    }
}
