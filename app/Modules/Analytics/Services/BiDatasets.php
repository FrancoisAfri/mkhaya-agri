<?php

namespace App\Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What can be exported, grouped by the module that owns it.
 *
 * ── Why a named list and not "export any table" ──────────────────────────────
 *
 * A warehouse table is a contract. Somebody builds a Power BI report on
 * `mkhaya_projects.progress` and it has to keep meaning progress next quarter. Exporting raw
 * tables would publish every internal column this platform happens to have today — soft-delete
 * flags, foreign keys, half-migrated columns — and each one becomes something a customer's report
 * breaks on the day it changes. What is listed here is a deliberate, stable surface.
 *
 * ── Why grouped by module ────────────────────────────────────────────────────
 *
 * Because that is how somebody looks for it: "what can I get out of CRM?" is the question, not
 * "what tables are there?". It also makes the entitlement obvious — a tenant is offered the
 * modules they actually run, and nothing else.
 *
 * ── The tenant boundary ──────────────────────────────────────────────────────
 *
 * Every query below scopes on company_id explicitly rather than trusting a global scope, because
 * this runs from a queue where CompanyScope is not applied and the result is written into a
 * customer's own warehouse. A leak here is not a page showing the wrong row; it is one customer's
 * data permanently inside another customer's BI tool.
 *
 * ── What is deliberately absent ──────────────────────────────────────────────
 *
 * No employee records, no medical or incident-injury detail, no disciplinary or whistleblower
 * data. Those are special personal information under POPIA s26 or are protected by an
 * expectation of confidence, and a warehouse is outside every control this platform applies to
 * them. Somebody who needs that data has a report inside the module that governs it.
 */
class BiDatasets
{
    /**
     * @return array<string, array{
     *   module: string,
     *   datasets: array<string, array{table: string, columns: array<string, string>, source: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'project_management' => [
                'module' => 'project_management',
                'datasets' => [
                    'projects' => [
                        'table' => 'mkhaya_projects',
                        'source' => 'pm_projects',
                        'columns' => [
                            'code' => 'STRING', 'name' => 'STRING', 'status' => 'STRING',
                            'stage' => 'STRING', 'priority' => 'STRING', 'currency' => 'STRING',
                            'planned_start' => 'DATE', 'planned_end' => 'DATE',
                            'budget_amount' => 'DECIMAL(18,2)',
                        ],
                    ],
                    'tasks' => [
                        'table' => 'mkhaya_tasks',
                        'source' => 'pm_tasks',
                        'columns' => [
                            'project_code' => 'STRING', 'code' => 'STRING', 'name' => 'STRING',
                            'status' => 'STRING', 'priority' => 'STRING',
                            'planned_start' => 'DATE', 'planned_end' => 'DATE',
                            'progress' => 'DECIMAL(5,2)',
                        ],
                    ],
                    'monthly_reports' => [
                        'table' => 'mkhaya_monthly_reports',
                        'source' => 'pm_monthly_reports',
                        'columns' => [
                            'period' => 'STRING', 'project_code' => 'STRING', 'project' => 'STRING',
                            'stage' => 'STRING', 'project_status' => 'STRING',
                            'progress' => 'DECIMAL(5,2)',
                            'estimated_fee' => 'DECIMAL(18,2)',
                            'invoiced' => 'DECIMAL(18,2)',
                            'outstanding' => 'DECIMAL(18,2)',
                        ],
                    ],
                ],
            ],

            'crm' => [
                'module' => 'crm',
                'datasets' => [
                    'accounts' => [
                        'table' => 'mkhaya_crm_accounts',
                        'source' => 'crm_accounts',
                        'columns' => [
                            'name' => 'STRING', 'industry' => 'STRING', 'city' => 'STRING',
                            'country' => 'STRING', 'health_status' => 'STRING',
                            'annual_revenue' => 'DECIMAL(18,2)',
                        ],
                    ],
                    'deals' => [
                        'table' => 'mkhaya_crm_deals',
                        'source' => 'crm_deals',
                        'columns' => [
                            'title' => 'STRING', 'account' => 'STRING', 'status' => 'STRING',
                            'currency' => 'STRING', 'amount' => 'DECIMAL(18,2)',
                            'probability' => 'DECIMAL(5,2)',
                            'expected_close_date' => 'DATE', 'closed_at' => 'DATE',
                        ],
                    ],
                ],
            ],

            'accounting' => [
                // The module's registered code_name, which is 'accounting' rather than anything
                // longer — forTenant() matches on it, so a guess here means the group is silently
                // never offered.
                'module' => 'accounting',
                'datasets' => [
                    'customers' => [
                        'table' => 'mkhaya_customers',
                        'source' => 'fin_parties',
                        'columns' => [
                            'code' => 'STRING', 'name' => 'STRING', 'category' => 'STRING',
                            'city' => 'STRING', 'country' => 'STRING',
                            'credit_limit' => 'DECIMAL(18,2)', 'is_active' => 'BOOLEAN',
                        ],
                    ],
                    'invoices' => [
                        'table' => 'mkhaya_invoices',
                        'source' => 'fin_invoices',
                        'columns' => [
                            'number' => 'STRING', 'party' => 'STRING', 'direction' => 'STRING',
                            'status' => 'STRING', 'currency' => 'STRING',
                            'invoice_date' => 'DATE', 'due_date' => 'DATE',
                            'subtotal' => 'DECIMAL(18,2)', 'tax_total' => 'DECIMAL(18,2)',
                            'total' => 'DECIMAL(18,2)', 'amount_paid' => 'DECIMAL(18,2)',
                        ],
                    ],
                ],
            ],

            'sheq' => [
                'module' => 'sheq',
                'datasets' => [
                    'incidents' => [
                        'table' => 'mkhaya_incidents',
                        'source' => 'sheq_incidents',
                        /*
                         * Counts, classifications and dates — the shape of a safety record. No
                         * description, no immediate action, and nothing at all from
                         * sheq_incident_people: the narrative names people and the injury detail
                         * is POPIA s26 special personal information, encrypted at rest here and
                         * outside every control this platform has once it reaches a warehouse.
                         */
                        'columns' => [
                            'reference' => 'STRING', 'incident_type' => 'STRING',
                            'outcome' => 'STRING', 'status' => 'STRING',
                            'statutory_category' => 'STRING',
                            'occurred_at' => 'DATE', 'reported_at' => 'DATE',
                            'actual_severity' => 'DECIMAL(5,2)',
                            'potential_severity' => 'DECIMAL(5,2)',
                            'days_lost' => 'DECIMAL(5,2)',
                            'is_reportable' => 'BOOLEAN',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The modules this tenant actually runs, with the datasets that are really there.
     *
     * Two filters, and both matter. A module the tenant has not bought should not be offered — the
     * list is the answer to "what can I get out of my system", and naming things they cannot have
     * makes it a sales page. And a dataset whose table does not exist is offered nowhere: a module
     * can be active in the registry while its migrations have never run on this install, and an
     * export that fails on a missing table looks like a broken feature rather than an absent one.
     *
     * @return list<array{module: string, name: string, datasets: list<array<string, mixed>>}>
     */
    public static function forTenant(): array
    {
        $active = DB::table('security_modules')
            ->where('active', true)
            ->pluck('name', 'code_name');

        $out = [];

        foreach (self::all() as $key => $group) {
            if (! $active->has($group['module'])) {
                continue;
            }

            $datasets = [];

            foreach ($group['datasets'] as $name => $definition) {
                if (! Schema::hasTable($definition['source'])) {
                    continue;
                }

                $datasets[] = [
                    'key' => $key . '.' . $name,
                    'name' => $name,
                    'table' => $definition['table'],
                    'columns' => array_keys($definition['columns']),
                ];
            }

            if ($datasets === []) {
                continue;
            }

            $out[] = [
                'module' => $group['module'],
                'name' => $active->get($group['module']),
                'datasets' => $datasets,
            ];
        }

        return $out;
    }

    /** Every dataset key that may be asked for, for validating a request. */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::all() as $group => $definition) {
            foreach (array_keys($definition['datasets']) as $name) {
                $keys[] = $group . '.' . $name;
            }
        }

        return $keys;
    }

    /** @return array{table: string, columns: array<string, string>, source: string}|null */
    public static function definition(string $key): ?array
    {
        [$group, $name] = array_pad(explode('.', $key, 2), 2, null);

        return self::all()[$group]['datasets'][$name] ?? null;
    }

    /**
     * The rows of one dataset for one company.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $key, int $companyId, int $limit = 50_000): array
    {
        $definition = self::definition($key);

        if ($definition === null || ! Schema::hasTable($definition['source'])) {
            return [];
        }

        return match ($key) {
            'project_management.projects' => DB::table('pm_projects')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('code')->limit($limit)
                ->get(['code', 'name', 'status', 'stage', 'priority', 'currency',
                    'planned_start', 'planned_end', 'budget_amount'])
                ->map(fn ($r) => (array) $r)->all(),

            'project_management.tasks' => DB::table('pm_tasks as t')
                ->leftJoin('pm_projects as p', 'p.id', '=', 't.project_id')
                ->where('t.company_id', $companyId)->whereNull('t.deleted_at')
                ->orderBy('p.code')->orderBy('t.code')->limit($limit)
                ->get([
                    'p.code as project_code', 't.code', 't.name', 't.status', 't.priority',
                    't.planned_start', 't.planned_end', 't.progress_pct as progress',
                ])
                ->map(fn ($r) => (array) $r)->all(),

            'project_management.monthly_reports' => DB::table('pm_monthly_reports as r')
                ->leftJoin('pm_reporting_periods as rp', 'rp.id', '=', 'r.reporting_period_id')
                ->leftJoin('pm_projects as p', 'p.id', '=', 'r.project_id')
                ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
                ->orderByDesc('rp.reporting_year')->orderByDesc('rp.reporting_month')
                ->limit($limit)
                ->get([
                    'rp.report_name as period', 'p.code as project_code', 'p.name as project',
                    'r.stage', 'r.project_status', 'r.progress_percentage as progress',
                    'r.estimated_professional_fee as estimated_fee',
                    'r.professional_fee_invoiced_to_date as invoiced',
                    'r.professional_fee_outstanding as outstanding',
                ])
                ->map(fn ($r) => (array) $r)->all(),

            'crm.accounts' => DB::table('crm_accounts')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('name')->limit($limit)
                ->get(['name', 'industry', 'city', 'country', 'health_status', 'annual_revenue'])
                ->map(fn ($r) => (array) $r)->all(),

            'crm.deals' => DB::table('crm_deals as d')
                ->leftJoin('crm_accounts as a', 'a.id', '=', 'd.account_id')
                ->where('d.company_id', $companyId)->whereNull('d.deleted_at')
                ->orderByDesc('d.id')->limit($limit)
                ->get([
                    'd.title', 'a.name as account', 'd.status', 'd.currency', 'd.amount',
                    'd.probability', 'd.expected_close_date', 'd.closed_at',
                ])
                ->map(fn ($r) => (array) $r)->all(),

            'accounting.customers' => DB::table('fin_parties')
                // No whereNull('deleted_at'): fin_parties does not soft-delete, and asking for a
                // column that is not there aborts the export rather than filtering it.
                ->where('company_id', $companyId)
                ->where('kind', 'customer')
                ->orderBy('code')->limit($limit)
                ->get(['code', 'name', 'category', 'city', 'country', 'credit_limit', 'is_active'])
                ->map(fn ($r) => (array) $r)->all(),

            'accounting.invoices' => DB::table('fin_invoices as i')
                ->leftJoin('fin_parties as p', 'p.id', '=', 'i.party_id')
                ->where('i.company_id', $companyId)
                ->orderByDesc('i.invoice_date')->limit($limit)
                ->get([
                    'i.number', 'p.name as party', 'i.direction', 'i.status', 'i.currency',
                    'i.invoice_date', 'i.due_date', 'i.subtotal', 'i.tax_total', 'i.total',
                    'i.amount_paid',
                ])
                ->map(fn ($r) => (array) $r)->all(),

            'sheq.incidents' => DB::table('sheq_incidents')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderByDesc('occurred_at')->limit($limit)
                ->get([
                    'reference', 'incident_type', 'outcome', 'status', 'statutory_category',
                    'occurred_at', 'reported_at', 'actual_severity', 'potential_severity',
                    'days_lost', 'is_reportable',
                ])
                ->map(fn ($r) => (array) $r)->all(),

            default => [],
        };
    }
}
