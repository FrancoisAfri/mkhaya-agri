<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAdjustment;
use App\Modules\Budget\Models\BudgetApproval;
use App\Modules\Budget\Models\BudgetCommitment;
use App\Modules\Budget\Models\BudgetConsumption;
use App\Modules\Budget\Models\BudgetOverride;
use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Budget\Models\BudgetRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budget fund lifecycle — the spine the whole procurement flow plugs into:
 *
 *   allocated  → the approved budget (plus increases/decreases, transfers)
 *   reserved   → soft hold when a purchase request is approved
 *   committed  → locked when a PO is issued (reservation → commitment)
 *   actual     → spent when an invoice/GRN posts (commitment → consumption)
 *   available  → allocated (+ approved overrides) − reserved − committed − actual
 */
class BudgetService
{
    // ── Creation / allocation / approval ─────────────────────────────────────────

    public function createBudget(array $data, ?int $userId = null): Budget
    {
        $data['code'] ??= $this->generateCode();
        $data['created_by'] ??= $userId;
        $data['status'] ??= 'draft';

        // `currency` is NOT NULL with a database default. A caller that has no currency — an
        // imported row whose column was blank — must leave the key out rather than pass null,
        // or the insert is rejected and the whole file fails on the first row without one.
        if (array_key_exists('currency', $data) && $data['currency'] === null) {
            unset($data['currency']);
        }

        $budget = Budget::create($data);

        if ($userId) {
            $budget->owners()->firstOrCreate(['user_id' => $userId], ['role' => 'owner']);
        }

        $this->recordRevision($budget, null, (float) $budget->allocated_amount, 'created', null, null, $userId);

        return $budget;
    }

    /**
     * Note what the allocation became, and why.
     *
     * Separate from the adjustment rows because those record what was *asked for*. These record
     * what the budget *is*, which is a different history the moment a transfer credits a budget
     * that requested nothing, or an increase raised in March is approved in June.
     */
    public function recordRevision(
        Budget $budget,
        ?float $previous,
        float $amount,
        string $reasonType,
        ?int $sourceId = null,
        ?string $reason = null,
        ?int $userId = null,
    ): BudgetRevision {
        $version = (int) BudgetRevision::where('budget_id', $budget->id)->max('version') + 1;

        return BudgetRevision::create([
            'budget_id'       => $budget->id,
            'version'         => $version,
            'previous_amount' => $previous,
            'amount'          => $amount,
            'reason_type'     => $reasonType,
            'source_id'       => $sourceId,
            'reason'          => $reason,
            'created_by'      => $userId,
        ]);
    }

    public function approveBudget(Budget $budget, ?int $userId = null, ?string $comment = null): Budget
    {
        BudgetApproval::create([
            'budget_id' => $budget->id, 'step' => 1, 'status' => 'approved',
            'approver_id' => $userId, 'comment' => $comment, 'acted_at' => now(),
        ]);
        $budget->update(['status' => 'active']);

        // The moment the allocation became real money. Everything after this is a revision to it.
        $this->recordRevision($budget, null, (float) $budget->allocated_amount, 'approved', null, $comment, $userId);

        return $budget->refresh();
    }

    public function rejectBudget(Budget $budget, ?int $userId = null, ?string $comment = null): Budget
    {
        BudgetApproval::create([
            'budget_id' => $budget->id, 'step' => 1, 'status' => 'rejected',
            'approver_id' => $userId, 'comment' => $comment, 'acted_at' => now(),
        ]);
        $budget->update(['status' => 'rejected']);

        return $budget->refresh();
    }

    // ── Adjustments / increases / transfers ─────────────────────────────────────

    public function adjustBudget(Budget $budget, string $type, float $amount, ?string $reason = null, ?int $userId = null, ?int $transferTo = null): BudgetAdjustment
    {
        return BudgetAdjustment::create([
            'budget_id' => $budget->id, 'type' => $type, 'amount' => $amount,
            'transfer_to_budget_id' => $transferTo, 'reason' => $reason,
            'status' => 'pending', 'requested_by' => $userId,
        ]);
    }

    /** Apply an approved adjustment to the allocated amount(s). */
    public function approveAdjustment(BudgetAdjustment $adjustment, ?int $userId = null): BudgetAdjustment
    {
        DB::transaction(function () use ($adjustment, $userId) {
            $budget = $adjustment->budget;
            $amount = (float) $adjustment->amount;
            $before = (float) $budget->allocated_amount;

            match ($adjustment->type) {
                'increase' => $budget->increment('allocated_amount', $amount),
                'decrease' => $this->applyDecrease($budget, $amount),
                'transfer' => $this->applyTransfer($budget, $adjustment->transfer_to_budget_id, $amount, $adjustment->id, $userId),
                default    => null,
            };

            $this->recordRevision(
                $budget,
                $before,
                (float) $budget->refresh()->allocated_amount,
                $adjustment->type === 'transfer' ? 'transfer_in' : 'adjustment',
                $adjustment->id,
                $adjustment->reason,
                $userId,
            );

            $adjustment->update(['status' => 'approved', 'approved_by' => $userId, 'acted_at' => now()]);
        });

        return $adjustment->refresh();
    }

    /**
     * Take allocation away.
     *
     * Refused when it would take the allocation below what is already spoken for. A decrease that
     * leaves a budget owing money on commitments it has already made is not a budget correction,
     * it is an overrun somebody created with a form — and the reservation behind it would have no
     * funds to draw on.
     */
    private function applyDecrease(Budget $budget, float $amount): void
    {
        $amounts = $this->amounts($budget);

        if ($amount > $amounts['available']) {
            throw new \RuntimeException(__('budget_management.errors.decrease_exceeds_available', [
                'available' => number_format($amounts['available'], 2),
            ]));
        }

        $budget->decrement('allocated_amount', $amount);
    }

    private function applyTransfer(Budget $from, ?int $toId, float $amount, ?int $adjustmentId = null, ?int $userId = null): void
    {
        // Resolve the target BEFORE debiting the source. Previously the source was
        // decremented unconditionally and the target only credited if it still existed
        // — a missing/soft-deleted target silently destroyed the funds. Throwing here
        // rolls back the whole approval transaction.
        $to = $toId ? Budget::find($toId) : null;
        if (! $to) {
            throw new \RuntimeException(__('budget_management.errors.transfer_target_missing'));
        }

        // Measured against what is free, not what was allocated: transferring away money that is
        // already reserved against a purchase request leaves that request drawing on nothing.
        $available = $this->amounts($from)['available'];

        if ($available < $amount) {
            throw new \RuntimeException(__('budget_management.errors.transfer_exceeds_available', [
                'available' => number_format($available, 2),
            ]));
        }

        $toBefore = (float) $to->allocated_amount;

        $from->decrement('allocated_amount', $amount);
        $to->increment('allocated_amount', $amount);

        // The receiving budget's own history: it changed, and nothing on its page asked for it.
        $this->recordRevision($to, $toBefore, $toBefore + $amount, 'transfer_in', $adjustmentId, __('budget_management.errors.transfer_from', ['code' => $from->code]), $userId);
    }

    // ── Overrides (permission to exceed budget) ─────────────────────────────────

    public function requestBudgetOverride(Budget $budget, float $amount, string $reason, ?int $userId = null, ?string $sourceType = null, ?int $sourceId = null): BudgetOverride
    {
        return BudgetOverride::create([
            'budget_id' => $budget->id, 'amount' => $amount, 'reason' => $reason,
            'status' => 'pending', 'requested_by' => $userId,
            'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
    }

    public function approveBudgetOverride(BudgetOverride $override, ?int $userId = null): BudgetOverride
    {
        $override->update(['status' => 'approved', 'approved_by' => $userId, 'acted_at' => now()]);

        return $override->refresh();
    }

    // ── Availability + the reserve → commit → consume ledger ────────────────────

    /**
     * The same figures as `amounts()`, for every budget, in one query.
     *
     * The tracking sweep, the analytics page and the budget list all need the position of every
     * budget at once. Doing it per row is four aggregates times the number of budgets — fine for a
     * demo tenant with three, an outage for one with four hundred.
     *
     * @return array<int, array{allocated:float,reserved:float,committed:float,actual:float,overrides:float,available:float}>
     */
    public function amountsForAll(?array $budgetIds = null): array
    {
        $bindings = [];
        $filter = '';

        if ($budgetIds !== null) {
            if ($budgetIds === []) {
                return [];
            }

            $filter = ' AND b.id IN (' . implode(',', array_fill(0, count($budgetIds), '?')) . ')';
            $bindings = array_map('intval', $budgetIds);
        }

        $rows = DB::select(<<<SQL
            SELECT b.id,
                   b.allocated_amount            AS allocated,
                   COALESCE(r.s, 0)              AS reserved,
                   COALESCE(c.s, 0)              AS committed,
                   COALESCE(x.s, 0)              AS actual,
                   COALESCE(o.s, 0)              AS overrides
            FROM budgets b
            LEFT JOIN (SELECT budget_id, SUM(amount) s FROM budget_reservations WHERE status = 'active' GROUP BY budget_id) r ON r.budget_id = b.id
            LEFT JOIN (SELECT budget_id, SUM(amount) s FROM budget_commitments  WHERE status = 'active' GROUP BY budget_id) c ON c.budget_id = b.id
            LEFT JOIN (SELECT budget_id, SUM(amount) s FROM budget_consumptions GROUP BY budget_id) x ON x.budget_id = b.id
            LEFT JOIN (SELECT budget_id, SUM(amount) s FROM budget_overrides    WHERE status = 'approved' GROUP BY budget_id) o ON o.budget_id = b.id
            WHERE b.deleted_at IS NULL{$filter}
            SQL, $bindings);

        $out = [];

        foreach ($rows as $row) {
            $allocated = (float) $row->allocated;
            $reserved  = (float) $row->reserved;
            $committed = (float) $row->committed;
            $actual    = (float) $row->actual;
            $overrides = (float) $row->overrides;

            $out[(int) $row->id] = [
                'allocated' => $allocated,
                'reserved'  => $reserved,
                'committed' => $committed,
                'actual'    => $actual,
                'overrides' => $overrides,
                'available' => round($allocated + $overrides - $reserved - $committed - $actual, 2),
            ];
        }

        return $out;
    }

    public function amounts(Budget $budget): array
    {
        $allocated = (float) $budget->allocated_amount;
        $reserved  = (float) $budget->reservations()->where('status', 'active')->sum('amount');
        $committed = (float) $budget->commitments()->where('status', 'active')->sum('amount');
        $actual    = (float) $budget->consumptions()->sum('amount');
        $overrides = (float) $budget->overrides()->where('status', 'approved')->sum('amount');

        return [
            'allocated' => $allocated,
            'reserved'  => $reserved,
            'committed' => $committed,
            'actual'    => $actual,
            'overrides' => $overrides,
            'available' => round($allocated + $overrides - $reserved - $committed - $actual, 2),
        ];
    }

    /**
     * May this budget carry another `$amount`?
     *
     * This is the check every other module goes through — procurement at submit and again at
     * approval, the marketplace at checkout. It answers two different questions in one shape:
     * whether there is room, and whether this company has said to stop regardless.
     *
     * The second is what makes a blocking alert rule mean anything. A rule enforced only on the
     * Budget module's own pages would be enforced only on the people who use them; a purchase order
     * raised in Procurement would sail past it, which is precisely the path the money takes.
     *
     * `ok` stays the field callers already branch on, so a blocking rule refuses everywhere without
     * any of them being changed. `reason` is added for the ones that can show it.
     *
     * @return array{ok:bool,available:float,shortfall:float,reason:?string,blocked:bool}
     */
    public function validateBudgetAvailability(Budget $budget, float $amount): array
    {
        $amounts = $this->amounts($budget);
        $available = $amounts['available'];

        // Resolved here rather than injected: the tracking service is built on this one, and
        // constructor-injecting it both ways is a cycle. Nothing else in this class needs it.
        $blockReason = app(BudgetTrackingService::class)->blockReason($budget, $amounts);

        return [
            'ok'        => $blockReason === null && $available >= $amount,
            'available' => $available,
            'shortfall' => max(0, round($amount - $available, 2)),
            'blocked'   => $blockReason !== null,
            'reason'    => $blockReason ?? ($available >= $amount ? null : __('budget_management.alerts.blocked_insufficient', [
                'requested' => number_format($amount, 2),
                'available' => number_format($available, 2),
            ])),
        ];
    }

    /**
     * Hold funds against a budget.
     *
     * `$status` exists so a reservation can be created without holding anything yet: a tenant that
     * routes reservations for approval creates them `pending`, and only an approved one counts
     * against the balance. Anything other than 'active' is invisible to `amounts()`, which is what
     * makes that safe.
     */
    public function reserveBudget(Budget $budget, float $amount, ?string $sourceType = null, ?int $sourceId = null, ?int $userId = null, string $status = 'active'): BudgetReservation
    {
        return BudgetReservation::create([
            'budget_id' => $budget->id, 'amount' => $amount, 'status' => $status,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'reserved_by' => $userId,
        ]);
    }

    /** Give locked funds back — a cancelled purchase order that was never invoiced. */
    public function releaseCommitment(BudgetCommitment $commitment): BudgetCommitment
    {
        $commitment->update(['status' => 'released']);

        return $commitment->refresh();
    }

    public function releaseReservedBudget(BudgetReservation $reservation): BudgetReservation
    {
        $reservation->update(['status' => 'released', 'released_at' => now()]);

        return $reservation->refresh();
    }

    /** Lock funds for a PO. If a reservation is given it is converted (released as a hold, recorded as committed). */
    public function commitBudget(Budget $budget, float $amount, ?string $sourceType = null, ?int $sourceId = null, ?BudgetReservation $reservation = null): BudgetCommitment
    {
        return DB::transaction(function () use ($budget, $amount, $sourceType, $sourceId, $reservation) {
            if ($reservation) {
                $reservation->update(['status' => 'committed', 'released_at' => now()]);
            }

            return BudgetCommitment::create([
                'budget_id' => $budget->id, 'amount' => $amount, 'status' => 'active',
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'reservation_id' => $reservation?->id,
            ]);
        });
    }

    /** Record actual spend. If a commitment is given it is closed so it no longer double-counts. */
    public function consumeBudget(Budget $budget, float $amount, ?string $sourceType = null, ?int $sourceId = null, ?BudgetCommitment $commitment = null, ?string $note = null): BudgetConsumption
    {
        return DB::transaction(function () use ($budget, $amount, $sourceType, $sourceId, $commitment, $note) {
            if ($commitment) {
                $commitment->update(['status' => 'consumed']);
            }

            return BudgetConsumption::create([
                'budget_id' => $budget->id, 'amount' => $amount,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'commitment_id' => $commitment?->id, 'note' => $note,
            ]);
        });
    }

    private function generateCode(): string
    {
        $max = (int) Budget::withTrashed()->max('id');

        return 'BGT'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
    }
}
