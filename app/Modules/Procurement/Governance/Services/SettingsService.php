<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant configuration: capability switches, reference prefixes, and every threshold the rest
 * of the module reads instead of hard-coding.
 *
 * A tenant with no settings row is not misconfigured — it is new. `get()` returns the defaults
 * without writing, so the module is usable from the first click, and `ensure()` materialises the
 * row the first time somebody actually changes something. That ordering matters on a platform
 * where a module can be switched on for hundreds of companies at once: creating a settings row per
 * company at activation time is a migration that gets slower every year, and creating one on first
 * read makes reads write, which then has to be safe on a replica.
 */
class SettingsService
{
    /** Mirrors the column defaults in the foundation migration. Kept in sync deliberately. */
    private const DEFAULTS = [
        'cap_planning' => true,
        'cap_tender_evaluation' => true,
        'cap_deviations' => true,
        'cap_reporting' => true,
        'cap_workload' => true,
        'cap_governance' => true,
        'record_prefix' => 'PROC',
        'review_prefix' => 'TR',
        'plan_prefix' => 'PP',
        'deviation_prefix' => 'DEV',
        'committee_prefix' => 'EC',
        'financial_year_start_month' => 4,
        'budget_warning_threshold_pct' => 90,
        'block_plan_over_budget' => false,
        'moderation_variance_threshold' => 20,
        'allow_evaluation_reopen' => true,
        'require_coi_declaration' => true,
        'workload_high_threshold' => 10,
        'workload_overload_threshold' => 15,
        'escalation_days' => 5,
        'evaluation_sla_days' => 14,
        'currency' => 'ZAR',

        /*
         * §1.6. Jurisdiction decides which preference framework a tender runs under; the rate basis
         * decides which day's exchange rate converts a bid for comparison.
         *
         * CLOSING is the default because it is the only basis under which every bid in a tender is
         * measured at the same instant. Converting each bid at its own submission date would let
         * two identical offers rank differently for having arrived on different days.
         */
        'jurisdiction' => 'ZA',
        'fx_rate_basis' => 'CLOSING',
        'fx_rate_type' => 'spot',
    ];

    /** @var array<int,object> per-request memo — settings are read many times per page. */
    private array $cache = [];

    public function get(int $companyId): object
    {
        if (isset($this->cache[$companyId])) {
            return $this->cache[$companyId];
        }

        $row = DB::table('procgov_settings')->where('company_id', $companyId)->first();

        return $this->cache[$companyId] = $row ?? (object) array_merge(
            self::DEFAULTS,
            ['id' => null, 'company_id' => $companyId],
        );
    }

    /** @param  array<string,mixed>  $values */
    public function update(int $companyId, array $values, ?AuditLedger $audit = null): object
    {
        $before = (array) $this->get($companyId);
        $allowed = array_intersect_key($values, self::DEFAULTS);

        if ($allowed === []) {
            return $this->get($companyId);
        }

        $this->ensure($companyId);

        DB::table('procgov_settings')
            ->where('company_id', $companyId)
            ->update(array_merge($allowed, ['updated_at' => Carbon::now()]));

        unset($this->cache[$companyId]);
        app(Capabilities::class)->forget($companyId);

        $changed = array_filter(
            $allowed,
            fn ($value, $key) => ($before[$key] ?? null) != $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed !== [] && $audit) {
            $audit->record($companyId, [
                'action' => 'settings_updated',
                'capability' => 'governance',
                'entity_type' => 'procgov_settings',
                'entity_id' => $companyId,
                'before' => array_intersect_key($before, $changed),
                'after' => $changed,
                'summary' => 'Updated Procurement Governance settings: '.implode(', ', array_keys($changed)),
            ]);
        }

        return $this->get($companyId);
    }

    /** Create the row if it does not exist yet, with the migration's defaults. */
    public function ensure(int $companyId): void
    {
        $exists = DB::table('procgov_settings')->where('company_id', $companyId)->exists();

        if ($exists) {
            return;
        }

        DB::table('procgov_settings')->insert(array_merge(
            self::DEFAULTS,
            [
                'company_id' => $companyId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ));

        unset($this->cache[$companyId]);
    }

    /**
     * The financial year label a date falls into, honouring the tenant's own year start. An
     * organisation whose year opens in April is in "2026/27" on 8 August 2026 and in "2025/26" on
     * 8 March 2026 — getting this wrong silently files a whole quarter's procurement against the
     * wrong year.
     */
    public function financialYearLabel(int $companyId, ?Carbon $on = null): string
    {
        $on ??= Carbon::today();
        $startMonth = (int) $this->get($companyId)->financial_year_start_month;

        if ($startMonth <= 1) {
            return (string) $on->year;
        }

        $startYear = $on->month >= $startMonth ? $on->year : $on->year - 1;

        return sprintf('%d/%02d', $startYear, ($startYear + 1) % 100);
    }

    /** The date range of a financial year label produced by financialYearLabel(). */
    public function financialYearRange(int $companyId, string $label): array
    {
        $startMonth = (int) $this->get($companyId)->financial_year_start_month;
        $startYear = (int) substr($label, 0, 4);

        $start = Carbon::create($startYear, max(1, $startMonth), 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
    }
}
