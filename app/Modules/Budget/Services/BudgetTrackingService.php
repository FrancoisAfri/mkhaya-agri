<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Exceptions\BudgetBlockedException;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAlert;
use App\Modules\Budget\Models\BudgetAlertRule;
use App\Modules\Budget\Models\BudgetOwner;
use App\Modules\Budget\Notifications\BudgetAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Budget tracking: what every budget's position is, who needs telling, and what stops.
 *
 * The module already knew, precisely, how much of every budget was reserved, committed and spent.
 * Nobody was told, and nothing was stopped. A budget crossed 90% on a Tuesday and the first anybody
 * heard of it was either a purchase order that would not go through, or — worse — one that did.
 *
 * Three jobs, deliberately in one place so they cannot disagree about the same number:
 *
 *   position   the arithmetic, per budget, in one query for the whole tenant
 *   alerts     rules the company wrote, evaluated nightly, raised once and re-stamped thereafter
 *   blocking   the top rung of the same ladder, enforced where money is encumbered rather than on
 *              a screen — a rule enforced only in the UI is a rule enforced only on the honest
 *
 * Blocking is a rule rather than a hard-coded 100% because companies genuinely differ: some stop at
 * 95% to leave room for what is already in flight, some allow operating overspend and never capital.
 * With no rules written, nothing is refused that was not refused before — but a negative balance is
 * still reported, because that is arithmetic rather than a preference.
 */
class BudgetTrackingService
{
    /** @var Collection<int, BudgetAlertRule>|null */
    private ?Collection $rules = null;

    public function __construct(private readonly BudgetService $budgets)
    {
    }

    /**
     * Every budget's position, keyed by id, with the percentages the rules are written against.
     *
     * @return array<int, array<string, float>>
     */
    public function positions(?array $budgetIds = null): array
    {
        $out = [];

        foreach ($this->budgets->amountsForAll($budgetIds) as $id => $amounts) {
            $out[$id] = $amounts + $this->percentages($amounts);
        }

        return $out;
    }

    /** @return array{committed_pct:float, actual_pct:float} */
    public function percentages(array $amounts): array
    {
        $ceiling = $amounts['allocated'] + $amounts['overrides'];

        if ($ceiling <= 0) {
            // No allocation means no percentage. Reporting an empty draft as 100% consumed would
            // bury the budgets that genuinely are.
            return ['committed_pct' => 0.0, 'actual_pct' => 0.0];
        }

        return [
            'committed_pct' => round((($amounts['reserved'] + $amounts['committed'] + $amounts['actual']) / $ceiling) * 100, 2),
            'actual_pct'    => round(($amounts['actual'] / $ceiling) * 100, 2),
        ];
    }

    /**
     * The rules this budget is currently in breach of, worst first.
     *
     * @return Collection<int, BudgetAlertRule>
     */
    public function breaches(Budget $budget, ?array $amounts = null): Collection
    {
        $amounts ??= $this->budgets->amounts($budget);

        return $this->rules()
            ->filter(fn (BudgetAlertRule $rule) => $rule->applies($budget))
            ->filter(fn (BudgetAlertRule $rule) => $rule->breached($amounts))
            ->sortByDesc(fn (BudgetAlertRule $rule) => $rule->rank())
            ->values();
    }

    /**
     * Why this budget will refuse new money, or null when it will not.
     *
     * Two reasons, and they are different in kind. A `block` rule is a decision the company made
     * and can change. A negative available balance is not — the funds are already spoken for, and
     * committing more of them is committing money that does not exist. The second is checked even
     * on a tenant with no rules at all.
     */
    public function blockReason(Budget $budget, ?array $amounts = null): ?string
    {
        $amounts ??= $this->budgets->amounts($budget);

        $blocking = $this->breaches($budget, $amounts)->first(fn (BudgetAlertRule $r) => $r->severity === 'block');

        if ($blocking) {
            return __('budget_management.alerts.blocked_by_rule', ['rule' => $blocking->name]);
        }

        if ($amounts['available'] < 0) {
            return __('budget_management.alerts.blocked_overrun', [
                'amount' => number_format(abs($amounts['available']), 2),
            ]);
        }

        // A budget that is not live has nothing to spend against yet. Saying so here means every
        // caller gets the same answer rather than each remembering to check the status itself.
        if (! in_array($budget->status, ['active', 'approved'], true)) {
            return __('budget_management.alerts.blocked_status', ['status' => $budget->status]);
        }

        return null;
    }

    /**
     * Refuse an encumbrance that would take the budget past what it is allowed to carry.
     *
     * Called at the point funds are held or locked, not at the point somebody opens a page. The
     * headroom check is deliberately separate from the block check: a budget can be perfectly
     * healthy and still not have R80,000 left, and those two refusals need different explanations
     * or the person on the other end cannot tell which problem they have.
     *
     * @throws BudgetBlockedException
     */
    public function assertCanEncumber(Budget $budget, float $amount): void
    {
        $amounts = $this->budgets->amounts($budget);

        if ($reason = $this->blockReason($budget, $amounts)) {
            throw new BudgetBlockedException($budget, $reason);
        }

        if ($amount > $amounts['available']) {
            throw new BudgetBlockedException($budget, __('budget_management.alerts.blocked_insufficient', [
                'requested' => number_format($amount, 2),
                'available' => number_format($amounts['available'], 2),
            ]), 'critical');
        }
    }

    /**
     * Evaluate every budget in the current tenant, raising, re-stamping and closing alerts.
     *
     * Returns what changed rather than what it looked at, because the command reports it and "8
     * budgets checked" tells nobody anything.
     *
     * @return array{raised:int, resolved:int, notified:int}
     */
    public function sweep(bool $notify = true): array
    {
        // The nightly command reuses one instance across every tenant. Without this, company two
        // would be evaluated against company one's rules — the memo turning a query saving into a
        // cross-tenant leak. A sweep is a fresh evaluation by definition, so this is also the
        // honest place for the boundary.
        $this->forgetRules();

        $rules = $this->rules();
        $budgets = Budget::query()->get();
        $positions = $this->positions();

        $raised = $resolved = $notified = 0;
        $seen = [];

        foreach ($budgets as $budget) {
            $amounts = $positions[$budget->id] ?? $this->budgets->amounts($budget);

            foreach ($rules as $rule) {
                if (! $rule->applies($budget) || ! $rule->breached($amounts)) {
                    continue;
                }

                [$alert, $isNew] = $this->raise($budget, $rule, $amounts);
                $seen[] = $alert->id;

                if ($isNew) {
                    $raised++;

                    if ($notify && $this->notifyFor($alert, $rule)) {
                        $notified++;
                    }
                }
            }

            // Arithmetic, not preference: an overrun is reported whether or not anybody wrote a
            // rule about it, and it is the one alert with no rule behind it.
            if ($amounts['available'] < 0) {
                [$alert, $isNew] = $this->raiseOverrun($budget, $amounts);
                $seen[] = $alert->id;

                if ($isNew) {
                    $raised++;

                    if ($notify && $this->notifyFor($alert, null)) {
                        $notified++;
                    }
                }
            }
        }

        // Anything live that this pass did not re-observe has stopped being true — an increase was
        // approved, a commitment released. Closing it is the whole reason the page stays readable.
        $stale = BudgetAlert::live()->when($seen, fn ($q) => $q->whereNotIn('id', $seen))->get();

        foreach ($stale as $alert) {
            $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
            $resolved++;
        }

        return ['raised' => $raised, 'resolved' => $resolved, 'notified' => $notified];
    }

    /** Acknowledge: somebody has seen it and is dealing with it. Not the same as fixed. */
    public function acknowledge(BudgetAlert $alert, ?int $userId, ?string $note = null): BudgetAlert
    {
        $alert->update([
            'status'          => 'acknowledged',
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'acknowledgement' => $note,
        ]);

        return $alert->fresh();
    }

    /**
     * The company's alert rules, read once.
     *
     * Memoised because the callers are loops. The tracking page and the dashboard both ask for a
     * block reason per budget, each of which asks for the rules — a tenant with four hundred
     * budgets was firing four hundred identical queries to fetch the same handful of rows. The
     * service is resolved per request, so the cache lives exactly as long as it should.
     *
     * @return Collection<int, BudgetAlertRule>
     */
    public function rules(): Collection
    {
        return $this->rules ??= BudgetAlertRule::active()->get();
    }

    /**
     * Drop the memo after the rules change, so a sweep run straight after an edit sees the edit.
     */
    public function forgetRules(): void
    {
        $this->rules = null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0: BudgetAlert, 1: bool} the alert, and whether this pass created it */
    private function raise(Budget $budget, BudgetAlertRule $rule, array $amounts): array
    {
        $existing = BudgetAlert::live()
            ->where('budget_id', $budget->id)
            ->where('rule_id', $rule->id)
            ->where('metric', $rule->metric)
            ->first();

        $observed = $rule->measure($amounts);

        if ($existing) {
            // Re-stamped, not re-raised. The observed figure moves; the alert and its
            // acknowledgement stay, so a page somebody triaged this morning still reads as triaged.
            $existing->update(['observed' => $observed, 'last_seen_at' => now()]);

            return [$existing, false];
        }

        return [BudgetAlert::create([
            'budget_id'    => $budget->id,
            'rule_id'      => $rule->id,
            'severity'     => $rule->severity,
            'metric'       => $rule->metric,
            'threshold'    => $rule->threshold,
            'observed'     => $observed,
            'message'      => $this->describe($budget, $rule, $observed),
            'status'       => 'open',
            'last_seen_at' => now(),
        ]), true];
    }

    /** @return array{0: BudgetAlert, 1: bool} */
    private function raiseOverrun(Budget $budget, array $amounts): array
    {
        $existing = BudgetAlert::live()
            ->where('budget_id', $budget->id)
            ->whereNull('rule_id')
            ->where('metric', 'available_amt')
            ->first();

        if ($existing) {
            $existing->update(['observed' => $amounts['available'], 'last_seen_at' => now()]);

            return [$existing, false];
        }

        return [BudgetAlert::create([
            'budget_id'    => $budget->id,
            'rule_id'      => null,
            'severity'     => 'critical',
            'metric'       => 'available_amt',
            'threshold'    => 0,
            'observed'     => $amounts['available'],
            'message'      => __('budget_management.alerts.msg_overrun', [
                'code'   => $budget->code,
                'amount' => number_format(abs($amounts['available']), 2),
            ]),
            'status'       => 'open',
            'last_seen_at' => now(),
        ]), true];
    }

    private function describe(Budget $budget, BudgetAlertRule $rule, float $observed): string
    {
        return match ($rule->metric) {
            'available_amt' => __('budget_management.alerts.msg_available', [
                'code'      => $budget->code,
                'available' => number_format($observed, 2),
                'threshold' => number_format((float) $rule->threshold, 2),
            ]),
            'actual_pct' => __('budget_management.alerts.msg_actual', [
                'code'    => $budget->code,
                'percent' => number_format($observed, 1),
            ]),
            default => __('budget_management.alerts.msg_committed', [
                'code'    => $budget->code,
                'percent' => number_format($observed, 1),
            ]),
        };
    }

    /**
     * Tell the people who asked to be told.
     *
     * `info` is deliberately silent: it exists so a company can watch something on the page without
     * an email about it, and sending one anyway would make the severity ladder meaningless.
     */
    private function notifyFor(BudgetAlert $alert, ?BudgetAlertRule $rule): bool
    {
        if ($alert->severity === 'info') {
            return false;
        }

        $recipients = $this->recipients($alert, $rule);

        if ($recipients->isEmpty()) {
            return false;
        }

        Notification::send($recipients, new BudgetAlertNotification($alert->load('budget')));
        $alert->update(['notified_at' => now()]);

        return true;
    }

    /** @return Collection<int, object> */
    private function recipients(BudgetAlert $alert, ?BudgetAlertRule $rule): Collection
    {
        $userClass = config('auth.providers.users.model');
        $ids = collect();

        if (! $rule || $rule->notify_owners) {
            $ids = $ids->merge(
                BudgetOwner::where('budget_id', $alert->budget_id)->pluck('user_id')
            );
        }

        $roles = $rule?->notify_roles ?? [];

        // With no rule (the overrun case) and no owners recorded, the alert would go nowhere. The
        // people who may approve budgets are the ones who can do something about it.
        $fallback = $ids->isEmpty() && ! $roles;

        $query = $userClass::query()->where(function ($q) use ($ids, $roles, $fallback) {
            $q->whereIn('id', $ids->filter()->unique()->all() ?: [0]);

            if ($roles) {
                $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', $roles));
            }

            if ($fallback) {
                $q->orWhereHas('permissions', fn ($p) => $p->where('name', 'budgets.approve'))
                    ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', 'budgets.approve'));
            }
        });

        return $query->limit(200)->get();
    }
}
