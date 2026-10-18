<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Procurement Planning (§2): the annual plan, its workflow, its budget position and the dashboard
 * management reads off it.
 *
 * The plan is where procurement stops being a series of surprises. A line item carries planned
 * dates and an estimated value; when it goes live it becomes a 360° record, and from then on the
 * plan can compare what was planned against what actually happened — which is what makes §2.4's
 * "delayed" and §3.4's cycle time measurable rather than asserted.
 *
 * On the workflow: transitions are validated against an explicit map rather than allowed freely.
 * A plan that can jump from DRAFT to APPROVED without passing budget verification defeats the
 * point of having the stages, and the map is the only place that can be true or false.
 */
class PlanningService
{
    /**
     * §2.2, as a graph. CANCELLED is reachable from anything not yet finished; nothing is reachable
     * from COMPLETED or CANCELLED, because a finished plan is a historical record.
     */
    public const TRANSITIONS = [
        'DRAFT' => ['DEPARTMENT_REVIEW', 'CANCELLED'],
        'DEPARTMENT_REVIEW' => ['BUDGET_VERIFICATION', 'DRAFT', 'CANCELLED'],
        'BUDGET_VERIFICATION' => ['PROCUREMENT_REVIEW', 'DEPARTMENT_REVIEW', 'CANCELLED'],
        'PROCUREMENT_REVIEW' => ['MANAGEMENT_APPROVAL', 'BUDGET_VERIFICATION', 'CANCELLED'],
        'MANAGEMENT_APPROVAL' => ['APPROVED', 'PROCUREMENT_REVIEW', 'CANCELLED'],
        'APPROVED' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
        private readonly BudgetBridge $budgets,
        private readonly ApprovalRouter $approvals,
        private readonly RecordService $records,
        private readonly SettingsService $settings,
    ) {}

    /** @param  array<string,mixed>  $data */
    public function createPlan(int $companyId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $data) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'plan');

            $planId = DB::table('procgov_plans')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $reference,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'financial_year_id' => $data['financial_year_id'] ?? null,
                'financial_year_label' => $data['financial_year_label'],
                'company_unit_id' => $data['company_unit_id'] ?? null,
                'business_unit_id' => $data['business_unit_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'budget_id' => $data['budget_id'] ?? null,
                'category' => $data['category'] ?? null,
                'owner_id' => $data['owner_id'] ?? auth()->id(),
                'status' => 'DRAFT',
                'currency' => $data['currency'] ?? $this->settings->get($companyId)->currency,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->logTransition($companyId, $planId, null, 'DRAFT', 'Plan created');

            $this->audit->record($companyId, [
                'action' => 'created',
                'capability' => 'planning',
                'entity_type' => 'procgov_plans',
                'entity_id' => $planId,
                'entity_reference' => $reference,
                'summary' => "Created procurement plan {$reference} for {$data['financial_year_label']}",
            ]);

            return DB::table('procgov_plans')->find($planId);
        });
    }

    /**
     * Add a procurement requirement to a plan (§2.1).
     *
     * @param  array<string,mixed>  $data
     */
    public function addItem(int $companyId, int $planId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $planId, $data) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'plan_item');

            $itemId = DB::table('procgov_plan_items')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'plan_id' => $planId,
                'reference' => $reference,
                'description' => $data['description'],
                'business_justification' => $data['business_justification'] ?? null,
                'category' => $data['category'] ?? null,
                'estimated_value' => $data['estimated_value'] ?? 0,
                'currency' => $data['currency'] ?? 'ZAR',
                'budget_id' => $data['budget_id'] ?? null,
                'budget_allocation' => $data['budget_allocation'] ?? null,
                'procurement_method' => $data['procurement_method'] ?? null,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_tender_date' => $data['planned_tender_date'] ?? null,
                'expected_award_date' => $data['expected_award_date'] ?? null,
                'expected_contract_start' => $data['expected_contract_start'] ?? null,
                'expected_contract_end' => $data['expected_contract_end'] ?? null,
                'responsible_officer_id' => $data['responsible_officer_id'] ?? null,
                'company_unit_id' => $data['company_unit_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'priority' => $data['priority'] ?? 'MEDIUM',
                'status' => 'PLANNED',
                'sort_order' => $data['sort_order'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recalculateTotals($companyId, $planId);

            $this->audit->record($companyId, [
                'action' => 'plan_item_added',
                'capability' => 'planning',
                'entity_type' => 'procgov_plan_items',
                'entity_id' => $itemId,
                'entity_reference' => $reference,
                'summary' => "Added plan line {$reference} — {$data['description']}",
                'after' => ['estimated_value' => $data['estimated_value'] ?? 0],
            ]);

            return DB::table('procgov_plan_items')->find($itemId);
        });
    }

    /** @param  array<string,mixed>  $data */
    public function updateItem(int $companyId, int $itemId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $itemId, $data) {
            $item = DB::table('procgov_plan_items')->find($itemId);
            $changes = [];

            foreach ($data as $key => $value) {
                if (($item->{$key} ?? null) != $value) {
                    $changes[$key] = ['from' => $item->{$key} ?? null, 'to' => $value];
                }
            }

            DB::table('procgov_plan_items')->where('id', $itemId)->update(
                array_merge($data, ['updated_at' => Carbon::now()])
            );

            $this->recalculateTotals($companyId, (int) $item->plan_id);

            if ($changes !== []) {
                $this->audit->record($companyId, [
                    'action' => 'updated',
                    'capability' => 'planning',
                    'entity_type' => 'procgov_plan_items',
                    'entity_id' => $itemId,
                    'entity_reference' => $item->reference,
                    'before' => array_map(fn ($c) => $c['from'], $changes),
                    'after' => array_map(fn ($c) => $c['to'], $changes),
                ]);
            }

            return DB::table('procgov_plan_items')->find($itemId);
        });
    }

    /**
     * Move a plan through the §2.2 workflow.
     *
     * @throws \RuntimeException on a transition the workflow does not allow
     */
    public function transition(int $companyId, int $planId, string $toStatus, ?string $comment = null): object
    {
        return DB::transaction(function () use ($companyId, $planId, $toStatus, $comment) {
            $plan = DB::table('procgov_plans')->lockForUpdate()->find($planId);

            if (! $plan) {
                throw new DomainRuleException('Plan not found.');
            }

            $allowed = self::TRANSITIONS[$plan->status] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw new DomainRuleException(
                    "A plan in {$plan->status} cannot move to {$toStatus}."
                    .($allowed === [] ? ' It is finished.' : ' Allowed: '.implode(', ', $allowed).'.')
                );
            }

            $now = Carbon::now();
            $update = ['status' => $toStatus, 'updated_by' => auth()->id(), 'updated_at' => $now];

            // Budget verification is the point at which the plan's financial position is captured,
            // so an approval later is demonstrably against a stated budget rather than a moving one.
            if ($toStatus === 'BUDGET_VERIFICATION') {
                $position = $this->budgetPosition($companyId, $planId);
                $update['budget_available_at_verification'] = $position['available'];
                $update['financials_refreshed_at'] = $now;
            }

            if ($toStatus === 'APPROVED') {
                $update['approved_at'] = $now;
                $update['approved_by'] = auth()->id();
                DB::table('procgov_plan_items')
                    ->where('plan_id', $planId)
                    ->where('status', 'PLANNED')
                    ->update(['status' => 'APPROVED', 'updated_at' => $now]);
            }

            if ($toStatus === 'CANCELLED') {
                $update['cancelled_at'] = $now;
                $update['cancellation_reason'] = $comment;
                $this->releaseAllReservations($companyId, $planId);
            }

            if ($toStatus === 'DEPARTMENT_REVIEW' && $plan->status === 'DRAFT') {
                $update['submitted_at'] = $now;
                $update['submitted_by'] = auth()->id();
            }

            DB::table('procgov_plans')->where('id', $planId)->update($update);

            $this->logTransition($companyId, $planId, $plan->status, $toStatus, $comment);

            $this->audit->record($companyId, [
                'action' => 'status_changed',
                'capability' => 'planning',
                'entity_type' => 'procgov_plans',
                'entity_id' => $planId,
                'entity_reference' => $plan->reference,
                'before' => ['status' => $plan->status],
                'after' => ['status' => $toStatus],
                'comment' => $comment,
                'summary' => "Procurement plan {$plan->reference} moved from {$plan->status} to {$toStatus}",
            ]);

            return DB::table('procgov_plans')->find($planId);
        });
    }

    /**
     * Raise the configured approval for a plan awaiting management sign-off, if the tenant has a
     * chain for it. A tenant with no chain still uses the status workflow — the approval chain adds
     * named approvers and an SLA, it is not a prerequisite for planning to work.
     */
    public function requestApproval(int $companyId, int $planId): ?object
    {
        $plan = DB::table('procgov_plans')->find($planId);

        if (! $plan) {
            return null;
        }

        $subject = new RuleSubject(
            companyId: $companyId,
            type: 'procgov_plans',
            id: $planId,
            reference: $plan->reference,
            fields: (array) $plan,
            amount: (float) $plan->total_estimated_value,
            category: $plan->category,
            companyUnitId: $plan->company_unit_id ? (int) $plan->company_unit_id : null,
            status: $plan->status,
            budgetId: $plan->budget_id ? (int) $plan->budget_id : null,
        );

        try {
            $request = $this->approvals->start('plan', $subject, ['label' => $plan->title]);
        } catch (\RuntimeException $e) {
            report($e);

            return null;
        }

        DB::table('procgov_plans')->where('id', $planId)->update([
            'approval_request_id' => $request->id,
            'updated_at' => Carbon::now(),
        ]);

        return $request;
    }

    /**
     * Take a plan line live: open a 360° record for it, hold the budget, and link the two so the
     * plan can show what became of every requirement.
     */
    public function activateItem(int $companyId, int $itemId): object
    {
        return DB::transaction(function () use ($companyId, $itemId) {
            $item = DB::table('procgov_plan_items')->lockForUpdate()->find($itemId);

            if (! $item) {
                throw new DomainRuleException('Plan line not found.');
            }

            if ($item->record_id) {
                return DB::table('procgov_records')->find($item->record_id);
            }

            $plan = DB::table('procgov_plans')->find($item->plan_id);

            $record = $this->records->open($companyId, [
                'title' => $item->description,
                'description' => $item->business_justification,
                'category' => $item->category ?? $plan?->category,
                'method' => $item->procurement_method,
                'company_unit_id' => $item->company_unit_id ?? $plan?->company_unit_id,
                'project_id' => $item->project_id ?? $plan?->project_id,
                'cost_centre' => $plan?->cost_centre,
                'officer_id' => $item->responsible_officer_id,
                'estimated_value' => $item->estimated_value,
                'currency' => $item->currency,
                'stage' => 'PLANNING',
                'financial_year_id' => $plan?->financial_year_id,
                'financial_year_label' => $plan?->financial_year_label,
                'target_completion_at' => $item->expected_award_date,
                'origin' => [
                    'link_role' => 'plan_item',
                    'entity_type' => 'procgov_plan_items',
                    'entity_id' => $itemId,
                    'entity_reference' => $item->reference,
                    'label' => $item->description,
                    'amount' => $item->estimated_value,
                ],
            ]);

            // Hold the money against the plan line. Absent Budget Management this is a no-op and
            // the line proceeds without a financial hold, which is the correct degradation.
            $reservationId = $this->budgets->reserve(
                $item->budget_id ? (int) $item->budget_id : null,
                (float) $item->estimated_value,
                'procgov_plan_items',
                $itemId,
                auth()->id(),
            );

            DB::table('procgov_plan_items')->where('id', $itemId)->update([
                'record_id' => $record->id,
                'status' => 'IN_PROGRESS',
                'actual_start_date' => Carbon::today(),
                'budget_reservation_id' => $reservationId,
                'updated_at' => Carbon::now(),
            ]);

            if ($plan && $plan->status === 'APPROVED') {
                DB::table('procgov_plans')->where('id', $plan->id)->update([
                    'status' => 'IN_PROGRESS',
                    'updated_at' => Carbon::now(),
                ]);
                $this->logTransition($companyId, (int) $plan->id, 'APPROVED', 'IN_PROGRESS', 'First line activated');
            }

            $this->audit->record($companyId, [
                'action' => 'plan_item_activated',
                'capability' => 'planning',
                'entity_type' => 'procgov_plan_items',
                'entity_id' => $itemId,
                'entity_reference' => $item->reference,
                'record_id' => (int) $record->id,
                'summary' => "Plan line {$item->reference} went live as procurement {$record->reference}",
            ]);

            return $record;
        });
    }

    /**
     * §2.3 budget integration. Every figure is nullable-aware: when Budget Management is absent the
     * budget-derived numbers are null and the UI says so, rather than showing zeros that read as
     * "no money".
     *
     * @return array<string,float|null|bool>
     */
    public function budgetPosition(int $companyId, int $planId): array
    {
        $plan = DB::table('procgov_plans')->find($planId);

        $planned = (float) DB::table('procgov_plan_items')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['CANCELLED'])
            ->sum('estimated_value');

        $budgetIds = DB::table('procgov_plan_items')
            ->where('plan_id', $planId)
            ->whereNotNull('budget_id')
            ->distinct()
            ->pluck('budget_id')
            ->when($plan?->budget_id, fn ($c) => $c->push($plan->budget_id))
            ->unique()
            ->filter()
            ->all();

        if (! $this->budgets->isAvailable() || $budgetIds === []) {
            return [
                'budget_available' => false,
                'planned' => $planned,
                'allocated' => null,
                'committed' => null,
                'actual' => null,
                'available' => null,
                'variance' => null,
                'over_budget' => false,
                'warning' => false,
            ];
        }

        $totals = ['allocated' => 0.0, 'reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'available' => 0.0];

        foreach ($budgetIds as $budgetId) {
            $amounts = $this->budgets->amounts((int) $budgetId);

            if ($amounts === null) {
                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (float) ($amounts[$key] ?? 0);
            }
        }

        $settings = $this->settings->get($companyId);
        $threshold = (float) $settings->budget_warning_threshold_pct / 100;

        return [
            'budget_available' => true,
            'planned' => round($planned, 2),
            'allocated' => round($totals['allocated'], 2),
            'reserved' => round($totals['reserved'], 2),
            'committed' => round($totals['committed'], 2),
            'actual' => round($totals['actual'], 2),
            'available' => round($totals['available'], 2),
            'variance' => round($totals['available'] - $planned, 2),
            'over_budget' => $planned > $totals['available'],
            // §2.3: warn before the plan actually breaches, not after.
            'warning' => $totals['available'] > 0
                && $planned > ($totals['available'] * $threshold)
                && $planned <= $totals['available'],
        ];
    }

    /** Refresh the committed/actual figures on every line from the Budget module. */
    public function refreshFinancials(int $companyId, int $planId): void
    {
        if (! $this->budgets->isAvailable()) {
            return;
        }

        $items = DB::table('procgov_plan_items')
            ->where('plan_id', $planId)
            ->whereNotNull('budget_id')
            ->get(['id', 'budget_id']);

        foreach ($items as $item) {
            $amounts = $this->budgets->amounts((int) $item->budget_id);

            if ($amounts === null) {
                continue;
            }

            DB::table('procgov_plan_items')->where('id', $item->id)->update([
                'committed_amount' => $amounts['committed'],
                'actual_spend' => $amounts['actual'],
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->recalculateTotals($companyId, $planId);

        DB::table('procgov_plans')->where('id', $planId)->update([
            'financials_refreshed_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * §2.4 procurement status dashboard.
     *
     * "Delayed" is computed rather than stored: a line whose planned tender date has passed while
     * it is still merely planned is delayed today whether or not a job ran last night. A stored
     * flag would let a plan look on time because a scheduler failed, which is the wrong direction
     * for a governance module to be wrong in.
     *
     * @param  array<string,mixed>  $filters
     */
    public function dashboard(int $companyId, array $filters = []): array
    {
        $today = Carbon::today();

        $items = DB::table('procgov_plan_items as i')
            ->join('procgov_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($filters['financial_year'] ?? null, fn ($q, $fy) => $q->where('p.financial_year_label', $fy))
            ->when($filters['company_unit_id'] ?? null, fn ($q, $u) => $q->where(function ($inner) use ($u) {
                $inner->where('i.company_unit_id', $u)->orWhere('p.company_unit_id', $u);
            }))
            ->when($filters['officer_id'] ?? null, fn ($q, $o) => $q->where('i.responsible_officer_id', $o))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('i.category', $c))
            ->get([
                'i.id', 'i.status', 'i.estimated_value', 'i.committed_amount', 'i.actual_spend',
                'i.planned_tender_date', 'i.expected_award_date', 'i.actual_award_date',
                'i.record_id', 'p.status as plan_status',
            ]);

        $delayed = $items->filter(function ($i) use ($today) {
            if (in_array($i->status, ['COMPLETED', 'CANCELLED', 'AWARDED'], true)) {
                return false;
            }

            $due = $i->expected_award_date ?? $i->planned_tender_date;

            return $due !== null && Carbon::parse($due)->lt($today);
        });

        $upcoming = $items->filter(function ($i) use ($today) {
            if (! in_array($i->status, ['PLANNED', 'APPROVED'], true) || ! $i->planned_tender_date) {
                return false;
            }

            return Carbon::parse($i->planned_tender_date)->between($today, $today->copy()->addDays(60));
        });

        $plannedValue = (float) $items->whereNotIn('status', ['CANCELLED'])->sum('estimated_value');
        $actualValue = (float) $items->sum(fn ($i) => (float) ($i->actual_spend ?? 0));

        return [
            'counts' => [
                'total' => $items->count(),
                'approved' => $items->where('status', 'APPROVED')->count(),
                'in_progress' => $items->where('status', 'IN_PROGRESS')->count(),
                'tendered' => $items->where('status', 'TENDERED')->count(),
                'awarded' => $items->where('status', 'AWARDED')->count(),
                'completed' => $items->where('status', 'COMPLETED')->count(),
                'cancelled' => $items->where('status', 'CANCELLED')->count(),
                'delayed' => $delayed->count(),
                'upcoming' => $upcoming->count(),
            ],
            'value' => [
                'planned' => round($plannedValue, 2),
                'committed' => round((float) $items->sum(fn ($i) => (float) ($i->committed_amount ?? 0)), 2),
                'actual' => round($actualValue, 2),
                'utilisation_pct' => $plannedValue > 0 ? round(($actualValue / $plannedValue) * 100, 1) : null,
            ],
            'delayed_ids' => $delayed->pluck('id')->values(),
            'upcoming_ids' => $upcoming->pluck('id')->values(),
        ];
    }

    public function transitionsFor(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function history(int $companyId, int $planId): Collection
    {
        return DB::table('procgov_plan_transitions')
            ->where('company_id', $companyId)
            ->where('plan_id', $planId)
            ->orderBy('occurred_at')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function recalculateTotals(int $companyId, int $planId): void
    {
        $totals = DB::table('procgov_plan_items')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['CANCELLED'])
            ->selectRaw('coalesce(sum(estimated_value),0) as estimated,
                         coalesce(sum(committed_amount),0) as committed,
                         coalesce(sum(actual_spend),0) as actual')
            ->first();

        DB::table('procgov_plans')->where('id', $planId)->update([
            'total_estimated_value' => $totals->estimated,
            'total_committed_value' => $totals->committed,
            'total_actual_value' => $totals->actual,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function releaseAllReservations(int $companyId, int $planId): void
    {
        $items = DB::table('procgov_plan_items')
            ->where('plan_id', $planId)
            ->whereNotNull('budget_reservation_id')
            ->get(['id', 'budget_reservation_id']);

        foreach ($items as $item) {
            if ($this->budgets->release((int) $item->budget_reservation_id)) {
                DB::table('procgov_plan_items')->where('id', $item->id)->update([
                    'budget_reservation_id' => null,
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    private function logTransition(int $companyId, int $planId, ?string $from, string $to, ?string $comment): void
    {
        $now = Carbon::now();

        $last = DB::table('procgov_plan_transitions')
            ->where('plan_id', $planId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        DB::table('procgov_plan_transitions')->insert([
            'company_id' => $companyId,
            'plan_id' => $planId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => auth()->id(),
            'actor_label' => auth()->user()?->email,
            'comment' => $comment,
            // Cast, and round, deliberately: Carbon returns a float with fractional seconds, and
            // the column is a bigint — an unrounded value is rejected outright by Postgres, which
            // would make every workflow transition fail.
            'seconds_in_from_status' => $last
                ? (int) max(0, round(Carbon::parse($last->occurred_at)->diffInSeconds($now)))
                : null,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
