<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tax liabilities and their settlement (§13).
 *
 * A payment is never made here. Money to SARS is paid and recorded in Finance — with Finance's own
 * approvals and its journal — and this service allocates that recorded payment to what it settles.
 * One payment may cover several liabilities and one liability may take several payments; an
 * allocation is reversed with a reason, never deleted, so the history of what was thought settled
 * when stays readable.
 */
class TaxPaymentService
{
    public function __construct(private readonly TaxAudit $audit)
    {
    }

    /** Payments recorded in Finance that still have money not allocated to a tax liability. */
    public function candidates(TaxLiability $liability, int $limit = 50): Collection
    {
        $allocated = TaxPaymentAllocation::query()->active()->select('fin_payment_id', DB::raw('SUM(amount) as used'))->groupBy('fin_payment_id');

        return DB::table('fin_payments as p')
            ->leftJoinSub($allocated, 'a', 'a.fin_payment_id', '=', 'p.id')
            ->leftJoin('fin_parties as party', 'party.id', '=', 'p.party_id')
            ->where('p.company_id', $liability->company_id)->where('p.direction', 'payment')
            ->when($liability->entity_id, fn ($q) => $q->where('p.entity_id', $liability->entity_id))
            ->whereRaw('p.amount - COALESCE(a.used, 0) > 0')
            ->when($liability->period?->end_date, fn ($q, $d) => $q->whereDate('p.payment_date', '>=', $d->copy()->subMonths(3)))
            ->orderByDesc('p.payment_date')->limit($limit)
            ->get(['p.id', 'p.number', 'p.payment_date', 'p.amount', 'p.reference', 'party.name as party',
                DB::raw('p.amount - COALESCE(a.used, 0) as available')]);
    }

    public function allocate(TaxLiability $liability, int $paymentId, string $amount, ?string $note = null): TaxPaymentAllocation
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new TaxControlException('Allocate a positive amount.');
        }
        if (in_array($liability->status, ['cancelled', 'refund_due'], true)) {
            throw new TaxControlException('This liability does not take payments.');
        }

        return DB::transaction(function () use ($liability, $paymentId, $amount, $note) {
            // Lock the payment so two allocations cannot both spend its last rand.
            $payment = DB::table('fin_payments')->where('id', $paymentId)->where('company_id', $liability->company_id)
                ->where('direction', 'payment')->lockForUpdate()->first();
            if (! $payment) {
                throw new TaxControlException('That payment is not a recorded Finance payment of this company.');
            }
            $used = (string) TaxPaymentAllocation::query()->active()->where('fin_payment_id', $paymentId)->sum('amount');
            $available = bcsub((string) $payment->amount, $used, 2);
            if (bccomp($amount, $available, 2) > 0) {
                throw new TaxControlException("Only R{$available} of payment {$payment->number} is still unallocated.");
            }

            $allocation = TaxPaymentAllocation::create([
                'company_id' => $liability->company_id, 'liability_id' => $liability->id, 'fin_payment_id' => $paymentId,
                'amount' => bcadd($amount, '0', 2), 'note' => $note, 'allocated_by' => Auth::id(), 'allocated_at' => now(),
            ]);
            $this->settle($liability);
            $this->audit->record('payment.allocated', $liability, ['period_id' => $liability->period_id, 'reason' => $note,
                'after' => ['payment' => $payment->number, 'amount' => $allocation->amount, 'outstanding' => $liability->fresh()->outstanding()]]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.payment.reconciled', ['liability_id' => $liability->id, 'company_id' => $liability->company_id]);

            return $allocation;
        });
    }

    public function reverse(TaxPaymentAllocation $allocation, string $reason): void
    {
        if ($allocation->reversed_at) {
            throw new TaxControlException('This allocation is already reversed.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new TaxControlException('Give the reason for reversing the allocation.');
        }

        DB::transaction(function () use ($allocation, $reason) {
            $allocation->forceFill(['reversed_at' => now(), 'reversed_by' => Auth::id(), 'reversal_reason' => $reason])->save();
            $liability = TaxLiability::findOrFail($allocation->liability_id);
            $this->settle($liability);
            $this->audit->record('payment.reversed', $liability, ['period_id' => $liability->period_id, 'reason' => $reason,
                'before' => ['amount' => (string) $allocation->amount, 'payment_id' => $allocation->fin_payment_id]]);
        });
    }

    /**
     * An assessment, interest or penalty from SARS, recorded from the notice (§13). The notice is
     * attached as evidence on the liability; an amount without one is not recorded.
     *
     * @param  array{tax_type_id:int, entity_id:?int, period_id:?int, source:string, description:string, amount:string, due_date:?string, notes:?string}  $data
     */
    public function recordAssessment(array $data): TaxLiability
    {
        if (! in_array($data['source'], ['assessment', 'interest', 'penalty'], true)) {
            throw new TaxControlException('Unknown liability source.');
        }

        return DB::transaction(function () use ($data) {
            $liability = TaxLiability::create([
                'entity_id' => $data['entity_id'] ?? null, 'tax_type_id' => $data['tax_type_id'], 'period_id' => $data['period_id'] ?? null,
                'source' => $data['source'], 'description' => $data['description'], 'original_amount' => bcadd($data['amount'], '0', 2),
                'due_date' => $data['due_date'] ?? null, 'status' => bccomp($data['amount'], '0', 2) < 0 ? 'refund_due' : 'open',
                'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            $this->audit->record('liability.recorded', $liability, ['period_id' => $liability->period_id, 'reason' => $data['description'],
                'after' => ['source' => $data['source'], 'amount' => (string) $liability->original_amount]]);

            return $liability;
        });
    }

    /** Recompute paid and status from active allocations. */
    private function settle(TaxLiability $liability): void
    {
        $paid = bcadd((string) TaxPaymentAllocation::query()->active()->where('liability_id', $liability->id)->sum('amount'), '0', 2);
        $owed = bcadd((string) $liability->original_amount, (string) $liability->adjustments, 2);
        $status = match (true) {
            bccomp($owed, '0', 2) < 0 => 'refund_due',
            bccomp($paid, '0', 2) === 0 => 'open',
            bccomp($paid, $owed, 2) < 0 => 'part_paid',
            bccomp($paid, $owed, 2) === 0 => 'paid',
            default => 'overpaid',
        };
        $liability->forceFill(['paid_amount' => $paid, 'status' => $status])->save();

        // §14 milestone: a fully paid period liability meets its payment deadline.
        if (in_array($status, ['paid', 'overpaid'], true) && $liability->period_id) {
            app(ComplianceCalendarService::class)->completeFor($liability->period_id, 'payment', "Paid in full ({$paid}) — {$liability->description}.");
        }

        if ($liability->return_id) {
            \App\Modules\Finance\Tax\Models\TaxReturn::whereKey($liability->return_id)->update([
                'payment_status' => ['open' => 'unpaid', 'part_paid' => 'part_paid', 'paid' => 'paid', 'overpaid' => 'paid', 'refund_due' => 'refund_due'][$status] ?? 'unpaid',
            ]);
        }
    }
}
