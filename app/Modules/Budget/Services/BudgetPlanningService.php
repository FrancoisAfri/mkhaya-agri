<?php

namespace App\Modules\Budget\Services;

use App\Core\User\Models\User;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetPhasing;
use App\Modules\Budget\Models\FinancialYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget preparation and the year-end: phasing, forecasting, carry-forward, bulk capture.
 *
 * The module could hold an allocation and account against it precisely, and had nothing to say
 * about the parts of budgeting that happen before and after the spending:
 *
 *   phasing        when the money is expected to go. Without it, month four compares four months of
 *                  spend against twelve months of budget and every budget looks comfortable.
 *   forecast       where the run rate lands by year end. A budget at 60% in month four is not fine;
 *                  it is a budget that will be 180% by December, and nothing said so.
 *   carry-forward  what happens to what was not spent. Doing this by hand is thirty budgets
 *                  retyped, and a typo becomes next year's allocation.
 *   bulk capture   the first week of a budget cycle is a spreadsheet somebody already has.
 */
class BudgetPlanningService
{
    public function __construct(private readonly BudgetService $budgets)
    {
    }

    // ── Phasing ──────────────────────────────────────────────────────────────

    /**
     * The plan for a budget, period by period, beside what has actually gone in each.
     *
     * Actuals are bucketed by the period a consumption's date falls in rather than by a stored
     * period id — spend arrives from procurement with a date and no opinion about budget periods,
     * and asking every caller to work one out would guarantee they disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function phasing(Budget $budget): array
    {
        $periods = $this->periodsFor($budget);

        if ($periods->isEmpty()) {
            return [];
        }

        $rows = BudgetPhasing::where('budget_id', $budget->id)->get()->keyBy('budget_period_id');
        $actuals = $this->actualsByPeriod($budget, $periods);
        $threshold = $this->varianceThreshold($budget);
        $names = $this->namesOf($rows->pluck('explained_by')->merge($rows->pluck('forecast_by'))->filter()->unique()->all());
        $today = now()->startOfDay();

        $cumulativePlan = 0.0;
        $cumulativeActual = 0.0;
        $cumulativeOutlook = 0.0;

        return $periods->map(function (BudgetPeriod $period) use ($rows, $actuals, $threshold, $names, $today, &$cumulativePlan, &$cumulativeActual, &$cumulativeOutlook) {
            $row = $rows->get($period->id);
            $plan = (float) ($row->amount ?? 0);
            $actual = (float) ($actuals[$period->id] ?? 0);
            $forecast = $row?->forecast_amount === null ? null : (float) $row->forecast_amount;
            $closed = Carbon::parse($period->end_date)->endOfDay()->lessThan($today);

            // What this period is now expected to cost. A closed period is what it was; an open
            // one is the owner's forecast, or the plan if they have not revised it — and never
            // less than what has already gone, because money spent cannot be forecast away.
            $outlook = $closed ? $actual : max($actual, $forecast ?? $plan);

            $variance = $plan - $actual;
            $variancePct = $plan > 0 ? round(abs($variance) / $plan * 100, 1) : ($actual > 0 ? 100.0 : 0.0);
            $material = $closed && $variancePct >= $threshold && abs($variance) >= 0.01;
            $explanation = $row?->variance_explanation;

            $cumulativePlan += $plan;
            $cumulativeActual += $actual;
            $cumulativeOutlook += $outlook;

            return [
                'period_id'  => $period->id,
                'label'      => $period->name,
                'start_date' => $period->start_date instanceof Carbon ? $period->start_date->toDateString() : (string) $period->start_date,
                'end_date'   => $period->end_date instanceof Carbon ? $period->end_date->toDateString() : (string) $period->end_date,
                'closed'     => $closed,
                'planned'    => round($plan, 2),
                'actual'     => round($actual, 2),
                'variance'   => round($variance, 2),
                'variance_pct' => $variancePct,
                'cumulative_planned' => round($cumulativePlan, 2),
                'cumulative_actual'  => round($cumulativeActual, 2),
                'forecast'       => $forecast,
                'forecast_note'  => $row?->forecast_note,
                'forecast_by'    => $row?->forecast_by ? ($names[$row->forecast_by] ?? null) : null,
                'forecast_at'    => $row?->forecast_at?->toDateTimeString(),
                'outlook'        => round($outlook, 2),
                'cumulative_outlook' => round($cumulativeOutlook, 2),
                'needs_explanation'  => $material && blank($explanation),
                'material'       => $material,
                'explanation'    => $explanation,
                'explained_by'   => $row?->explained_by ? ($names[$row->explained_by] ?? null) : null,
                'explained_at'   => $row?->explained_at?->toDateTimeString(),
            ];
        })->all();
    }

    /**
     * Write the plan.
     *
     * The total is not forced to equal the allocation. A plan that adds to more than the budget is
     * a legitimate thing to be looking at — it is exactly the conversation phasing exists to start
     * — and refusing to save it would mean the tool only accepts plans that already balance.
     *
     * @param  array<int, array{period_id:int, amount:float|string|null, note?:string|null}>  $lines
     */
    public function setPhasing(Budget $budget, array $lines): void
    {
        $valid = $this->periodsFor($budget)->pluck('id')->all();

        DB::transaction(function () use ($budget, $lines, $valid) {
            foreach ($lines as $line) {
                $periodId = (int) ($line['period_id'] ?? 0);

                // A period from another financial year would render on nobody's phasing table and
                // silently distort the totals of whichever year it did appear in.
                if (! in_array($periodId, $valid, true)) {
                    continue;
                }

                BudgetPhasing::updateOrCreate(
                    ['budget_id' => $budget->id, 'budget_period_id' => $periodId],
                    ['amount' => (float) ($line['amount'] ?? 0), 'note' => $line['note'] ?? null],
                );
            }
        });
    }

    /**
     * Spread the allocation evenly, giving the rounding remainder to the last period.
     *
     * The remainder has to land somewhere; putting it at the end means eleven identical months and
     * one that differs by cents, rather than twelve figures none of which are round.
     */
    public function spreadEvenly(Budget $budget): void
    {
        $periods = $this->periodsFor($budget);

        if ($periods->isEmpty()) {
            return;
        }

        $total = (float) $budget->allocated_amount;
        $each = round($total / $periods->count(), 2);
        $lines = [];

        foreach ($periods->values() as $index => $period) {
            $isLast = $index === $periods->count() - 1;

            $lines[] = [
                'period_id' => $period->id,
                'amount'    => $isLast ? round($total - ($each * ($periods->count() - 1)), 2) : $each,
            ];
        }

        $this->setPhasing($budget, $lines);
    }

    // ── Forecast ─────────────────────────────────────────────────────────────

    /**
     * Where this budget lands by year end, and whether the pace is a problem.
     *
     * Two projections, because they answer different questions. The run-rate one extrapolates what
     * has actually been spent — it is the honest one when spending is even. The committed one adds
     * what is already reserved and locked but not yet invoiced, and it is the one that catches a
     * budget that looks fine only because its purchase orders have not been billed yet.
     *
     * Returns nulls rather than guesses when there is no financial year to measure elapsed time
     * against. A forecast built on an assumed year is a number people would act on.
     *
     * @return array<string, float|string|null>
     */
    public function forecast(Budget $budget, ?array $amounts = null): array
    {
        $amounts ??= $this->budgets->amounts($budget);
        $year = $budget->financialYear;

        $ceiling = $amounts['allocated'] + $amounts['overrides'];

        if (! $year || ! $year->start_date || ! $year->end_date) {
            return [
                'elapsed_pct'  => null,
                'run_rate'     => null,
                'projected'    => null,
                'projected_committed' => null,
                'projected_variance'  => null,
                'verdict'      => 'no_year',
            ];
        }

        $start = Carbon::parse($year->start_date);
        $end = Carbon::parse($year->end_date);
        $days = max(1, $start->diffInDays($end));
        $elapsed = min($days, max(0, $start->diffInDays(now())));
        $fraction = $elapsed / $days;

        if ($fraction <= 0) {
            return [
                'elapsed_pct'  => 0.0,
                'run_rate'     => null,
                'projected'    => null,
                'projected_committed' => null,
                'projected_variance'  => null,
                'verdict'      => 'not_started',
            ];
        }

        $projected = round($amounts['actual'] / $fraction, 2);
        $projectedCommitted = round(($amounts['actual'] + $amounts['committed'] + $amounts['reserved']) / $fraction, 2);

        return [
            'elapsed_pct' => round($fraction * 100, 1),
            'run_rate'    => round($amounts['actual'] / max(1, $elapsed), 2),
            'projected'   => $projected,
            'projected_committed' => $projectedCommitted,
            'projected_variance'  => round($ceiling - $projected, 2),
            'verdict'     => match (true) {
                $ceiling <= 0            => 'no_allocation',
                $projected > $ceiling    => 'over',
                $projected > $ceiling * 0.95 => 'tight',
                default                  => 'on_track',
            },
        ];
    }

    // ── Reforecast ───────────────────────────────────────────────────────────

    /**
     * The owner's revised year-end figure, beside the arithmetic projection.
     *
     * Closed periods count at actual; open periods at the owner's forecast, or the plan where they
     * have not revised it. Null when nobody has entered a forecast: a "reforecast" that is only
     * the original plan restated would look like a judgement somebody made, and nobody did.
     *
     * @param  array<int, array<string, mixed>>|null  $phasing
     * @return array{reforecast: float|null, reforecast_variance: float|null, reforecast_at: string|null, reforecast_by: string|null}
     */
    public function reforecast(Budget $budget, ?array $phasing = null, ?array $amounts = null): array
    {
        $phasing ??= $this->phasing($budget);
        $entered = collect($phasing)->filter(fn ($p) => $p['forecast'] !== null);

        if ($entered->isEmpty()) {
            return ['reforecast' => null, 'reforecast_variance' => null, 'reforecast_at' => null, 'reforecast_by' => null];
        }

        $amounts ??= $this->budgets->amounts($budget);
        $total = round(collect($phasing)->sum('outlook'), 2);
        $latest = $entered->sortByDesc('forecast_at')->first();

        return [
            'reforecast'          => $total,
            'reforecast_variance' => round($amounts['allocated'] + $amounts['overrides'] - $total, 2),
            'reforecast_at'       => $latest['forecast_at'],
            'reforecast_by'       => $latest['forecast_by'],
        ];
    }

    /**
     * Record the owner's revised forecast for the open periods.
     *
     * Closed periods are skipped: their figure is the actual, and a forecast for the past would
     * only let a missed month be restated after the fact. A blank amount clears the forecast and
     * the period falls back to its plan.
     *
     * @param  array<int, array{period_id:int, amount:float|string|null, note?:string|null}>  $lines
     */
    public function setForecast(Budget $budget, array $lines, ?int $userId = null): int
    {
        $open = $this->periodsFor($budget)
            ->filter(fn (BudgetPeriod $p) => ! Carbon::parse($p->end_date)->endOfDay()->lessThan(now()->startOfDay()))
            ->pluck('id')->all();
        $changed = 0;

        DB::transaction(function () use ($budget, $lines, $open, $userId, &$changed) {
            foreach ($lines as $line) {
                $periodId = (int) ($line['period_id'] ?? 0);
                if (! in_array($periodId, $open, true)) {
                    continue;
                }

                $amount = ($line['amount'] ?? '') === '' || $line['amount'] === null ? null : round((float) $line['amount'], 2);
                $row = BudgetPhasing::firstOrNew(['budget_id' => $budget->id, 'budget_period_id' => $periodId]);
                $before = $row->forecast_amount === null ? null : (float) $row->forecast_amount;
                $note = $line['note'] ?? $row->forecast_note;

                if ($row->exists && $before === $amount && $note === $row->forecast_note) {
                    continue;
                }

                $row->fill([
                    'amount'          => $row->exists ? $row->amount : 0,
                    'forecast_amount' => $amount,
                    'forecast_note'   => $note,
                    'forecast_by'     => $userId,
                    'forecast_at'     => now(),
                ])->save();
                $changed++;
            }
        });

        return $changed;
    }

    /** Say why a period missed its plan. Only a closed period has a variance to explain. */
    public function explainVariance(Budget $budget, int $periodId, string $explanation, ?int $userId = null): bool
    {
        $period = $this->periodsFor($budget)->firstWhere('id', $periodId);
        if (! $period || ! Carbon::parse($period->end_date)->endOfDay()->lessThan(now()->startOfDay())) {
            return false;
        }

        $row = BudgetPhasing::firstOrNew(['budget_id' => $budget->id, 'budget_period_id' => $periodId]);
        $row->fill([
            'amount'               => $row->exists ? $row->amount : 0,
            'variance_explanation' => trim($explanation),
            'explained_by'         => $userId,
            'explained_at'         => now(),
        ])->save();

        return true;
    }

    /**
     * Every material variance in closed periods, explained or not, across budgets.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function materialVariances(?int $financialYearId = null, ?int $budgetId = null, bool $onlyUnexplained = false): Collection
    {
        return Budget::query()
            ->with('financialYear')
            ->whereNotNull('financial_year_id')
            ->whereNotIn('status', ['draft', 'rejected'])
            ->when($financialYearId, fn ($q, $id) => $q->where('financial_year_id', $id))
            ->when($budgetId, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('code')
            ->get()
            ->flatMap(fn (Budget $b) => collect($this->phasing($b))
                ->filter(fn ($p) => $p['material'] && (! $onlyUnexplained || $p['needs_explanation']))
                ->map(fn ($p) => $p + ['budget_id' => $b->id, 'budget_uuid' => $b->uuid, 'code' => $b->code, 'name' => $b->name]))
            ->values();
    }

    private function varianceThreshold(Budget $budget): float
    {
        $pct = $budget->financialYear?->variance_threshold_pct;

        return $pct === null ? FinancialYear::DEFAULT_VARIANCE_THRESHOLD_PCT : (float) $pct;
    }

    /** @return array<int, string> user id => display name (HR name, else email) */
    private function namesOf(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return User::with('person')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($u) => [$u->id => ($u->person
                ? (trim(($u->person->first_name ?? '') . ' ' . ($u->person->surname ?? '')) ?: $u->email)
                : $u->email)])
            ->all();
    }

    // ── Carry-forward / year end ─────────────────────────────────────────────

    /**
     * Copy a year's budgets into the next one.
     *
     * Two modes, because companies mean two different things by "roll over":
     *
     *   allocation  next year starts with the same number this year had. The common one — last
     *               year's budget is the starting point for the conversation about this year's.
     *   unspent     next year starts with what was left. Grant funding and capital projects work
     *               this way, and getting it wrong in either direction is somebody's programme.
     *
     * Nothing is activated. Every copy lands as a draft, because a budget that became live because
     * a job ran at 2am is one nobody agreed to.
     *
     * @return array{created:int, skipped:int}
     */
    public function carryForward(FinancialYear $from, FinancialYear $to, string $mode = 'allocation', ?int $userId = null): array
    {
        $source = Budget::query()->where('financial_year_id', $from->id)->get();
        $positions = $this->budgets->amountsForAll($source->pluck('id')->all());

        $created = $skipped = 0;

        foreach ($source as $budget) {
            // Already carried: running the job twice must not double a department's budget.
            $exists = Budget::where('financial_year_id', $to->id)
                ->where('name', $budget->name)
                ->where('scope_type', $budget->scope_type)
                ->where('scope_label', $budget->scope_label)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $amounts = $positions[$budget->id] ?? $this->budgets->amounts($budget);
            $amount = $mode === 'unspent'
                ? max(0, $amounts['available'])
                : (float) $budget->allocated_amount;

            // Carrying nothing forward is not carrying a budget forward. A fully spent budget
            // would otherwise arrive as a zero-rand draft nobody wants and everybody has to delete.
            if ($mode === 'unspent' && $amount <= 0) {
                $skipped++;

                continue;
            }

            $copy = $this->budgets->createBudget([
                'name'               => $budget->name,
                'financial_year_id'  => $to->id,
                'budget_category_id' => $budget->budget_category_id,
                'expenditure_type'   => $budget->expenditure_type,
                'scope_type'         => $budget->scope_type,
                'scope_id'           => $budget->scope_id,
                'scope_label'        => $budget->scope_label,
                'currency'           => $budget->currency,
                'allocated_amount'   => $amount,
                'status'             => 'draft',
                'description'        => $budget->description,
            ], $userId);

            // Owners come across too. A carried budget with nobody on it sends its warnings
            // nowhere, and next year is exactly when nobody is watching yet.
            foreach ($budget->owners as $owner) {
                $copy->owners()->firstOrCreate(['user_id' => $owner->user_id], ['role' => $owner->role]);
            }

            $this->budgets->recordRevision(
                $copy, null, $amount, 'carry_forward', $budget->id,
                __('budget_management.planning.carried_from', ['code' => $budget->code, 'year' => $from->name]),
                $userId,
            );

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ── Bulk capture ─────────────────────────────────────────────────────────

    /**
     * Import budgets from a CSV.
     *
     * The first week of a budget cycle is a spreadsheet somebody already has, and retyping thirty
     * cost centres by hand is how a digit goes missing. Rows are validated individually and a bad
     * one is reported by line number rather than aborting the file — an import that refuses
     * everything because row 19 has a blank name is one people give up on.
     *
     * Errors stop a row; warnings do not. The difference matters to the person holding the file: a
     * misspelled category is worth telling them about, but refusing the whole row over it would
     * mean re-uploading thirty good rows to fix one word.
     *
     * @param  array<int, array<string, string|null>>  $rows  keyed by header
     * @return array{created:int, errors:array<int, string>, warnings:array<int, string>}
     */
    public function import(array $rows, FinancialYear $year, ?int $userId = null): array
    {
        // Matched case-insensitively: nobody types "Facilities" the same way twice, and a category
        // that silently did not attach is a budget that reports under the wrong heading forever.
        $categories = DB::table('budget_categories')
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => $id]);

        $created = 0;
        $errors = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // header is line 1, and people count from the file they are looking at
            $name = trim((string) ($row['name'] ?? ''));
            $amount = $this->parseAmount($row['allocated_amount'] ?? $row['amount'] ?? null);

            if ($name === '') {
                $errors[] = __('budget_management.planning.import_no_name', ['line' => $line]);

                continue;
            }

            if ($amount === null) {
                $errors[] = __('budget_management.planning.import_bad_amount', [
                    'line'  => $line,
                    'value' => trim((string) ($row['allocated_amount'] ?? $row['amount'] ?? '')),
                ]);

                continue;
            }

            $type = mb_strtolower(trim((string) ($row['expenditure_type'] ?? '')));
            $scope = mb_strtolower(trim((string) ($row['scope_type'] ?? '')));
            $categoryName = trim((string) ($row['category'] ?? ''));
            $categoryId = $categoryName === '' ? null : ($categories[mb_strtolower($categoryName)] ?? null);

            // Each of these is a value we quietly replaced with a default. Said out loud, because a
            // budget that came in as opex when the file said "Opperating" is one nobody notices
            // until the capital report is short.
            if ($categoryName !== '' && $categoryId === null) {
                $warnings[] = __('budget_management.planning.import_unknown_category', ['line' => $line, 'value' => $categoryName]);
            }

            if ($type !== '' && ! in_array($type, ['capex', 'opex'], true)) {
                $warnings[] = __('budget_management.planning.import_unknown_type', ['line' => $line, 'value' => $type]);
            }

            if ($scope !== '' && ! in_array($scope, ['department', 'project', 'category', 'general'], true)) {
                $warnings[] = __('budget_management.planning.import_unknown_scope', ['line' => $line, 'value' => $scope]);
            }

            $this->budgets->createBudget([
                'name'               => $name,
                'financial_year_id'  => $year->id,
                'budget_category_id' => $categoryId,
                'expenditure_type'   => in_array($type, ['capex', 'opex'], true) ? $type : 'opex',
                'scope_type'         => in_array($scope, ['department', 'project', 'category', 'general'], true) ? $scope : 'general',
                'scope_label'        => $this->text($row['scope_label'] ?? null),
                'currency'           => $this->currency($row['currency'] ?? null),
                'allocated_amount'   => $amount,
                'status'             => 'draft',
                'description'        => $this->text($row['description'] ?? null),
            ], $userId);

            $created++;
        }

        return ['created' => $created, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The header row and two rows showing what good input looks like.
     *
     * Kept beside the importer rather than in the page, because the two have to agree: a template
     * that drifts from the parser is worse than no template, since people trust it.
     *
     * The sample category is the tenant's own, not an invented one. A downloaded template that
     * warns "no category called Facilities" the moment it is uploaded unchanged teaches people that
     * the warnings are noise — and then they stop reading the ones that matter.
     *
     * @return array<int, array<int, string>>
     */
    public function templateRows(): array
    {
        $category = (string) (DB::table('budget_categories')
            ->when(Schema::hasColumn('budget_categories', 'status'), fn ($q) => $q->where('status', true))
            ->orderBy('name')
            ->value('name') ?? '');

        return [
            ['name', 'allocated_amount', 'expenditure_type', 'category', 'scope_type', 'scope_label', 'currency', 'description'],
            ['Head Office Utilities', '250000.00', 'opex', $category, 'department', 'Head Office', 'ZAR', 'Electricity, water and refuse'],
            // Second row deliberately leaves the optional columns empty, so the file shows that
            // blank is allowed rather than only showing what a filled-in row looks like.
            ['Fleet Replacement Programme', '1850000', 'capex', '', 'project', 'Phase 2 vehicles', '', 'Six replacement bakkies'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Read an amount the way a spreadsheet writes one.
     *
     * Excel exports "R 250 000,00" and " 1,850,000.00 " depending on the machine's locale, and a
     * parser that only accepts bare digits rejects most real files. Anything still ambiguous after
     * stripping the decoration is refused rather than guessed at — a misread allocation is worse
     * than a rejected row.
     */
    private function parseAmount(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // Currency symbols, letters and spaces (including the non-breaking kind Excel emits).
        $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';

        // A comma used as the decimal separator: "250000,00". Distinguished from a thousands
        // separator by there being exactly one, with two digits after it and no full stop.
        if (preg_match('/^-?\d+,\d{1,2}$/', $value)) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function text(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));

        return $value === '' ? null : $value;
    }

    /** A three-letter code, or nothing — the column is too small to hold anything else. */
    private function currency(mixed $raw): ?string
    {
        $value = mb_strtoupper(trim((string) ($raw ?? '')));

        return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return Collection<int, BudgetPeriod> */
    private function periodsFor(Budget $budget): Collection
    {
        if (! $budget->financial_year_id) {
            return collect();
        }

        return BudgetPeriod::where('financial_year_id', $budget->financial_year_id)
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Actual spend bucketed into the periods it falls in.
     *
     * One query with a CASE rather than one per period: a twelve-month year would otherwise be
     * twelve round trips per budget, and the phasing table is rendered per budget.
     *
     * @return array<int, float>
     */
    private function actualsByPeriod(Budget $budget, Collection $periods): array
    {
        $rows = DB::table('budget_consumptions')
            ->where('budget_id', $budget->id)
            ->get(['amount', 'created_at']);

        $out = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at);

            $period = $periods->first(fn (BudgetPeriod $p) => $date->betweenIncluded(
                Carbon::parse($p->start_date)->startOfDay(),
                Carbon::parse($p->end_date)->endOfDay(),
            ));

            if (! $period) {
                continue;
            }

            $out[$period->id] = ($out[$period->id] ?? 0) + (float) $row->amount;
        }

        return $out;
    }
}
