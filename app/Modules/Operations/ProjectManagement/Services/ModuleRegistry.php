<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The conditional-integration framework: what else is installed, and therefore which of PM's
 * integration features are real for this tenant right now.
 *
 * The delivery brief specifies a `module_registry` table holding licensed/active/capabilities per
 * tenant. This platform already answers exactly that question — `security_modules` says what
 * exists and is switched on, and CompanyEntitlements says what the tenant's plan or signed licence
 * grants — so a second table would be a copy that drifts. This service is that table's interface,
 * backed by the platform's own state (PM-ADR-001's principle applied to entitlements).
 *
 * The rule this enforces everywhere: Project Management must be fully usable with nothing else
 * installed. Integrations *add* capability; their absence never blocks a workflow. Every caller
 * asks `has()` before offering an integration action, and falls back to PM-local data (an
 * ExternalRef with source_module 'local') when the answer is no.
 */
class ModuleRegistry
{
    /**
     * Capabilities PM knows how to consume, by module. A capability is a promise about a specific
     * integration point, not just "the module exists" — so a module can gain capabilities over time
     * without PM guessing what it can do.
     */
    private const CAPABILITIES = [
        'leave_management'    => ['hr.employees', 'hr.leave'],
        'time_attendance'     => ['hr.employees', 'hr.attendance'],
        'payroll'             => ['hr.employees', 'hr.labour_rates'],
        'crm'                 => ['crm.customers', 'crm.opportunities'],
        'accounting'          => ['finance.cost_centres', 'finance.postings', 'finance.invoices'],
        'budget_management'   => ['finance.budgets'],
        'procurement_requests' => ['procurement.requisitions', 'procurement.orders'],
        'supplier_management' => ['procurement.suppliers'],
        'contract_management' => ['contracts.register'],
        'asset_management'    => ['assets.register'],
        'fleet_management'    => ['fleet.vehicles'],
        'help_desk'           => ['helpdesk.tickets'],
        'learning_management' => ['lms.competencies'],
    ];

    /** How long a resolved registry is trusted. Short: a plan change must take effect promptly. */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly CompanyEntitlements $entitlements,
        private readonly CurrentCompany $currentCompany,
    ) {
    }

    /**
     * Is this module installed, switched on, and included in the tenant's plan or licence?
     * All three must hold — an active module the tenant has not paid for is not available to them.
     */
    public function isAvailable(string $moduleCode): bool
    {
        return in_array($moduleCode, $this->availableModules(), true);
    }

    /**
     * Does the tenant have this specific integration capability, e.g. 'hr.employees'?
     * This is what feature code should ask; it survives a module being renamed or split.
     */
    public function has(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** @return list<string> every capability available to the current tenant */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->availableModules() as $module) {
            foreach (self::CAPABILITIES[$module] ?? [] as $capability) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    /**
     * The shape every API response embeds so a client knows which integration actions to render,
     * rather than offering a button that 403s or silently does nothing.
     *
     * @return array<string,bool>
     */
    public function forResponse(): array
    {
        $all = [];

        foreach (self::CAPABILITIES as $capabilities) {
            foreach ($capabilities as $capability) {
                $all[$capability] = false;
            }
        }

        foreach ($this->capabilities() as $capability) {
            $all[$capability] = true;
        }

        ksort($all);

        return $all;
    }

    /** Drop the cached view — call after a plan change or a module being switched on/off. */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> module code_names this tenant can actually use */
    private function availableModules(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function () {
            $active = DB::table('security_modules')
                ->where('active', 1)
                ->pluck('code_name')
                ->all();

            return array_values(array_filter(
                $active,
                fn (string $code) => $this->entitlements->currentAllowsModule($code)
            ));
        });
    }

    /**
     * Per tenant: entitlements differ between companies, and a platform admin with no active
     * company sees everything. Caching without the company in the key would leak one tenant's
     * integration surface to another.
     */
    private function cacheKey(): string
    {
        return 'pm.module_registry.' . ($this->currentCompany->id() ?? 'platform');
    }
}
