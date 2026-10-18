<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\AssetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\BudgetProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TicketProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\VehicleProvider;

/**
 * What each integration actually does, and where in Project Management it is used.
 *
 * The setup page used to list capability keys with "Available" beside them, which answered the
 * wrong question. A tenant reading it learned that `finance.budgets` was available and nothing
 * about whether anything in this module reads a Finance budget — and mostly, nothing did. A
 * capability the product advertises and no screen consumes is a promise, not a feature.
 *
 * So each entry carries three things the page could not previously say:
 *
 *   `uses`   the PM surfaces that consume it, by route name — the answer to "where is this used?"
 *   `seam`   the provider interface it upgrades, or null when it is consumed directly
 *   `built`  whether PM's side of the integration exists *today*
 *
 * `built` is the honest one and the reason this class is worth having. Three states come out of it:
 *
 *   live     the module is available and PM consumes it — the integration is doing something
 *   ready    the module is available, PM's side is not built — nothing is happening yet
 *   off      the module is not available to this tenant
 *
 * Keeping `built` accurate is a maintenance obligation: when an integrated provider is written,
 * the flag moves here in the same change. A stale `true` is worse than a `false`, because it tells
 * somebody a number they are reading is being fed by a module that is not feeding it.
 */
final class CapabilityCatalogue
{
    /**
     * capability => [uses, seam, built]
     *
     * `uses` is a list of route names. They are resolved to links on the page, so a reader can go
     * and look at the screen rather than take the description on trust.
     *
     * @var array<string, array{uses: list<string>, seam: ?class-string, built: bool}>
     */
    private const ENTRIES = [
        // ── Live: PM has an integrated implementation and calls it ────────────
        'hr.employees' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => EmployeeProvider::class,
            'built' => true,
        ],
        'hr.leave' => [
            // Enforced when a task is assigned: booking a foreman who is on approved leave is the
            // mistake standalone mode cannot catch.
            'uses'  => ['project-management.delivery', 'project-management.work'],
            'seam'  => EmployeeProvider::class,
            'built' => true,
        ],
        'crm.customers' => [
            'uses'  => ['project-management.projects.index', 'project-management.setup.clients'],
            'seam'  => ClientProvider::class,
            'built' => true,
        ],

        // ── Also live ─────────────────────────────────────────────────────────
        // Each resolves to a PM-local implementation when the module is absent, which is what keeps
        // Project Management fully usable standalone. `uses` names the screen that consumes it.
        // Attended hours are shown beside booked hours on the team table — the two disagreeing is
        // the signal, so they are never collapsed into one number.
        'hr.attendance' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => EmployeeProvider::class,
            'built' => true,
        ],
        'hr.labour_rates' => [
            'uses'  => ['project-management.delivery', 'project-management.insights.reports'],
            'seam'  => TimeSink::class,
            'built' => true,
        ],
        'crm.opportunities' => [
            'uses'  => ['project-management.projects.index'],
            'seam'  => ClientProvider::class,
            'built' => true,
        ],
        // Finance's budget wins over the project's own field when it holds one for the project.
        'finance.budgets' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => BudgetProvider::class,
            'built' => true,
        ],
        'finance.cost_centres' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => BudgetProvider::class,
            'built' => true,
        ],
        'finance.invoices' => [
            'uses'  => ['project-management.delivery', 'project-management.figures'],
            'seam'  => BudgetProvider::class,
            'built' => true,
        ],
        'finance.postings' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => ExpenseSink::class,
            'built' => true,
        ],
        'procurement.requisitions' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => PurchaseProvider::class,
            'built' => true,
        ],
        'procurement.orders' => [
            'uses'  => ['project-management.delivery', 'project-management.figures'],
            'seam'  => PurchaseProvider::class,
            'built' => true,
        ],
        'procurement.suppliers' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => PurchaseProvider::class,
            'built' => true,
        ],
        'contracts.register' => [
            'uses'  => ['project-management.projects.index'],
            'seam'  => ContractProvider::class,
            'built' => true,
        ],
        'assets.register' => [
            'uses'  => ['project-management.work'],
            'seam'  => AssetProvider::class,
            'built' => true,
        ],
        'fleet.vehicles' => [
            'uses'  => ['project-management.work'],
            'seam'  => VehicleProvider::class,
            'built' => true,
        ],
        'helpdesk.tickets' => [
            'uses'  => ['project-management.projects.index'],
            'seam'  => TicketProvider::class,
            'built' => true,
        ],
        'lms.competencies' => [
            'uses'  => ['project-management.delivery'],
            'seam'  => EmployeeProvider::class,
            'built' => true,
        ],
    ];

    /** @return array<string, array{uses: list<string>, seam: ?class-string, built: bool}> */
    public static function all(): array
    {
        return self::ENTRIES;
    }

    public static function isBuilt(string $capability): bool
    {
        return self::ENTRIES[$capability]['built'] ?? false;
    }

    /**
     * The state a reader should be shown.
     *
     * @return 'live'|'ready'|'off'
     */
    public static function state(string $capability, bool $available): string
    {
        if (! $available) {
            return 'off';
        }

        return self::isBuilt($capability) ? 'live' : 'ready';
    }

    /**
     * The whole surface, ready to render.
     *
     * @param  array<string,bool>  $available  capability => available to this tenant
     * @return list<array<string,mixed>>
     */
    public static function describe(array $available): array
    {
        $rows = [];

        foreach ($available as $capability => $isAvailable) {
            $entry = self::ENTRIES[$capability] ?? ['uses' => [], 'seam' => null, 'built' => false];

            $rows[] = [
                'key'    => $capability,
                'area'   => explode('.', $capability)[0],
                'state'  => self::state($capability, $isAvailable),
                // Route names, filtered to ones that actually exist — a link to a route somebody
                // renamed is worse than no link.
                'uses'   => array_values(array_filter(
                    $entry['uses'],
                    fn (string $name) => \Illuminate\Support\Facades\Route::has($name),
                )),
                'seam'   => $entry['seam'] ? class_basename($entry['seam']) : null,
            ];
        }

        return $rows;
    }

    /** For the summary line: how many are genuinely doing something. */
    public static function counts(array $available): array
    {
        $live = 0;
        $ready = 0;

        foreach ($available as $capability => $isAvailable) {
            match (self::state($capability, $isAvailable)) {
                'live'  => $live++,
                'ready' => $ready++,
                default => null,
            };
        }

        return ['live' => $live, 'ready' => $ready, 'total' => count($available)];
    }
}
