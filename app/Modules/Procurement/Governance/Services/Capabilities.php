<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * §12 module activation, one level finer than the platform's module gate.
 *
 * The brief asks for a tree: Procurement Management with Planning, Tender Evaluation, Deviations,
 * Reporting, Workload and Governance beneath it — and specifically for the case where procurement
 * is active but governance was not purchased, so normal procurement keeps working while the
 * governance surfaces stay dark.
 *
 * The platform already answers the outer question (`security_modules` says what is installed and
 * switched on, `CompanyEntitlements` says what the tenant's plan or signed licence grants), so this
 * class does not duplicate it — it *composes* with it. A capability is available when all three
 * hold:
 *
 *   1. the `procurement_governance` module is installed and active,
 *   2. the tenant's plan or licence includes it, and
 *   3. the tenant has not switched that particular capability off in its settings.
 *
 * The third is a tenant's own choice — an organisation that runs planning in another system turns
 * planning off and stops seeing plan cards, approvals and reminders, without losing the rest.
 *
 * Also answers "what else is installed", so integration actions are only offered when the module
 * behind them exists. The rule that governs every one of those call sites: this module must be
 * fully usable with nothing else installed. Integrations *add* capability; their absence degrades a
 * view, it never blocks a workflow.
 */
class Capabilities
{
    public const MODULE = 'procurement_governance';

    /** The capabilities the module ships, in the order they appear in navigation. */
    public const ALL = [
        'planning',           // §2  Procurement Planning
        'tender_evaluation',  // §1  Tender Review & Evaluation
        'deviations',         // §5  Deviation Management
        'reporting',          // §3  Procurement Reporting
        'workload',           // §4  Workload & Capacity
        'governance',         // §5.3 rules engine, §6 360° record, §7 audit trail
    ];

    /** capability => the settings column that switches it off for a tenant. */
    private const SETTING_COLUMN = [
        'planning' => 'cap_planning',
        'tender_evaluation' => 'cap_tender_evaluation',
        'deviations' => 'cap_deviations',
        'reporting' => 'cap_reporting',
        'workload' => 'cap_workload',
        'governance' => 'cap_governance',
    ];

    /**
     * Neighbouring modules this one knows how to consume, and what it uses each for. Asked before
     * an integration action is offered, so a button never appears that would 403 or quietly do
     * nothing.
     */
    private const INTEGRATIONS = [
        'procurement_requests' => ['procurement.requests', 'procurement.requisitions', 'procurement.orders'],
        'supplier_management' => ['procurement.suppliers', 'procurement.supplier_performance'],
        'contract_management' => ['contracts.register'],
        'budget_management' => ['finance.budgets'],
        'accounting' => ['finance.invoices', 'finance.cost_centres'],
        'tender_pursuit' => ['tenders.pursuit'],
        'asset_management' => ['assets.register'],
        'project_management' => ['projects.register'],
    ];

    /** Short: a plan change or a settings toggle must take effect promptly. */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly CompanyEntitlements $entitlements,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /** Is the module itself installed, switched on, and included in this tenant's plan or licence? */
    public function moduleAvailable(): bool
    {
        return $this->isModuleActive(self::MODULE) && $this->entitlements->currentAllowsModule(self::MODULE);
    }

    /** Is this capability usable right now, for this tenant? */
    public function has(string $capability): bool
    {
        if (! isset(self::SETTING_COLUMN[$capability])) {
            return false;
        }

        return in_array($capability, $this->enabled(), true);
    }

    /** @return list<string> every capability available to the current tenant */
    public function enabled(): array
    {
        if (! $this->moduleAvailable()) {
            return [];
        }

        $companyId = $this->currentCompany->id();

        // A platform admin with no active company sees the full surface; there is no tenant whose
        // settings could narrow it, and pretending otherwise hides configuration screens from the
        // only person who can fix a tenant's configuration.
        if ($companyId === null) {
            return self::ALL;
        }

        return Cache::remember("procgov.caps.{$companyId}", self::CACHE_TTL, function () use ($companyId) {
            $settings = DB::table('procgov_settings')->where('company_id', $companyId)->first();

            // No settings row yet — the tenant has just been granted the module, and every
            // capability is on until they say otherwise. Same default as the migration.
            if (! $settings) {
                return self::ALL;
            }

            return array_values(array_filter(
                self::ALL,
                fn (string $cap) => (bool) ($settings->{self::SETTING_COLUMN[$cap]} ?? true)
            ));
        });
    }

    /** Is a neighbouring module installed, active and licensed to this tenant? */
    public function moduleInstalled(string $moduleCode): bool
    {
        return $this->isModuleActive($moduleCode) && $this->entitlements->currentAllowsModule($moduleCode);
    }

    /** Does the tenant have a specific integration capability, e.g. 'finance.budgets'? */
    public function integratesWith(string $integration): bool
    {
        foreach (self::INTEGRATIONS as $module => $provides) {
            if (in_array($integration, $provides, true) && $this->moduleInstalled($module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shape every page payload embeds, so the client renders the integration actions that are
     * real and omits the ones that are not.
     *
     * @return array{capabilities: array<string,bool>, integrations: array<string,bool>}
     */
    public function forResponse(): array
    {
        $enabled = $this->enabled();

        $capabilities = [];
        foreach (self::ALL as $cap) {
            $capabilities[$cap] = in_array($cap, $enabled, true);
        }

        $integrations = [];
        foreach (self::INTEGRATIONS as $module => $provides) {
            $installed = $this->moduleInstalled($module);
            foreach ($provides as $integration) {
                $integrations[$integration] = $installed;
            }
        }
        ksort($integrations);

        return ['capabilities' => $capabilities, 'integrations' => $integrations];
    }

    /** Drop the cached view — call after a settings change or a plan change. */
    public function forget(?int $companyId = null): void
    {
        $companyId ??= $this->currentCompany->id();

        if ($companyId !== null) {
            Cache::forget("procgov.caps.{$companyId}");
        }
    }

    private function isModuleActive(string $moduleCode): bool
    {
        return Cache::remember(
            "module_active_{$moduleCode}",
            self::CACHE_TTL,
            fn () => DB::table('security_modules')
                ->where('code_name', $moduleCode)
                ->where('active', 1)
                ->exists()
        );
    }
}
