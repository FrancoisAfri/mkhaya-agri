<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\AssetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractorProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\RevenueSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TicketProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\VehicleProvider;
use App\Modules\Operations\ProjectManagement\Providers\Local;
use App\Modules\Operations\ProjectManagement\Providers\Integrated;

/**
 * Resolves each integration seam to a Local or an Integrated implementation (PM-1041).
 *
 * The rule is a capability lookup, not a config file: if the tenant has `hr.employees`, asking for
 * an EmployeeProvider returns the HR-backed one; otherwise it returns the PM-local one. Feature
 * code asks for the interface and never learns which it got — that is what stops
 * `if (hrInstalled)` spreading through the codebase, which is exactly why this epic is scheduled
 * before anything that touches another module.
 *
 * A seam with no integrated implementation yet simply has no capability entry and always resolves
 * local. That is a truthful statement of where we are, and adding the integration later is one
 * line here plus the class — no feature code changes.
 */
class ProviderRegistry
{
    /**
     * seam => [capability that upgrades it, integrated class, local class].
     *
     * A null capability means "no integration exists yet"; the local implementation is the whole
     * story until one is written.
     *
     * @var array<class-string, array{0: ?string, 1: ?class-string, 2: class-string}>
     */
    private const BINDINGS = [
        EmployeeProvider::class => ['hr.employees', Integrated\HrEmployeeProvider::class, Local\LocalEmployeeProvider::class],
        ClientProvider::class   => ['crm.customers', Integrated\CrmClientProvider::class, Local\LocalClientProvider::class],
        // PM owns subcontractors alone, and points at the supplier register where there is one.
        ContractorProvider::class => ['procurement.suppliers', Integrated\SupplierContractorProvider::class, Local\LocalContractorProvider::class],
        ExpenseSink::class      => ['finance.postings', Integrated\AccountingExpenseSink::class, Local\LocalExpenseSink::class],
        // The other half: costs have had a route to the ledger for a while, issued invoices had none.
        RevenueSink::class      => ['finance.postings', Integrated\AccountingRevenueSink::class, Local\LocalRevenueSink::class],
        PurchaseProvider::class => ['procurement.orders', Integrated\ProcurementPurchaseProvider::class, Local\LocalPurchaseProvider::class],
        AssetProvider::class    => ['assets.register', Integrated\AssetRegisterProvider::class, Local\LocalAssetProvider::class],
        VehicleProvider::class  => ['fleet.vehicles', Integrated\FleetVehicleProvider::class, Local\LocalVehicleProvider::class],
        DocumentStore::class    => [null, null, Local\LocalDocumentStore::class],
        TimeSink::class         => ['hr.labour_rates', Integrated\PayrollTimeSink::class, Local\LocalTimeSink::class],
        ContractProvider::class => ['contracts.register', Integrated\ContractRegisterProvider::class, Local\LocalContractProvider::class],
        TicketProvider::class   => ['helpdesk.tickets', Integrated\HelpDeskTicketProvider::class, Local\LocalTicketProvider::class],
        BudgetProvider::class   => ['finance.budgets', Integrated\FinanceBudgetProvider::class, Local\LocalBudgetProvider::class],
    ];

    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $seam
     * @return T
     */
    public function for(string $seam): object
    {
        [$capability, $integrated, $local] = self::BINDINGS[$seam]
            ?? throw new \InvalidArgumentException("No provider binding for {$seam}.");

        if ($capability !== null && $integrated !== null && $this->modules->has($capability)) {
            return app($integrated);
        }

        return app($local);
    }

    /** True when this seam currently resolves to a module-backed implementation. */
    public function isIntegrated(string $seam): bool
    {
        [$capability, $integrated] = self::BINDINGS[$seam] ?? [null, null];

        return $capability !== null && $integrated !== null && $this->modules->has($capability);
    }

    /**
     * Which seams are integrated right now, for the capability manifest the client renders from
     * (PM-1042) and for the system-health view.
     *
     * @return array<string, array{capability: ?string, integrated: bool}>
     */
    public function status(): array
    {
        $out = [];

        foreach (self::BINDINGS as $seam => [$capability, $integrated]) {
            $out[class_basename($seam)] = [
                'capability' => $capability,
                'integrated' => $capability !== null && $integrated !== null && $this->modules->has($capability),
            ];
        }

        return $out;
    }

    /** @return list<class-string> every seam, for tests that must cover all of them */
    public static function seams(): array
    {
        return array_keys(self::BINDINGS);
    }
}
