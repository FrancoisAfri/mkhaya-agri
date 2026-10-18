<?php

namespace App\Modules\ContractManagement\Observers;

use App\Modules\Budget\Services\BudgetLedgerBridge;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A contract commits the budget the moment both parties have signed it.
 *
 * That is the point the money stops being a plan. Before signature a contract is a negotiation and
 * committing against it would hold funds the company might never owe; after it, the obligation
 * exists whether or not an invoice has arrived, and a budget that only counts invoices will happily
 * approve a second contract it cannot afford.
 *
 * A commitment rather than spend, deliberately. Nothing has left the bank yet — this is money
 * spoken for. It converts to actual spend as invoices post, exactly as a purchase order does.
 *
 * Hung off the envelope rather than the contract because signature completion happens inside the
 * e-signature module, and the last signer is what makes it true. The observer lives here, in the
 * contract module, so ESign keeps knowing nothing about contracts or budgets: an envelope that
 * belongs to no contract falls straight through.
 */
class ContractSignatureBudgetObserver
{
    public function __construct(private readonly BudgetLedgerBridge $bridge) {}

    public function updated(Envelope $envelope): void
    {
        // Only the transition matters. Re-saving a completed envelope for any other reason must not
        // look like a fresh signature.
        if (! $envelope->wasChanged('status') || $this->statusOf($envelope) !== EnvelopeStatus::Completed->value) {
            return;
        }

        $contract = Contract::where('esign_envelope_id', $envelope->id)->first();

        if (! $contract) {
            return; // an envelope for something else entirely
        }

        try {
            $this->post($contract, $envelope);
        } catch (Throwable $e) {
            // Signing must never fail because a budget is misconfigured. The signature is the fact;
            // telling the budget about it is bookkeeping that follows.
            Log::warning('contracts: budget commitment hook failed', [
                'contract' => $contract->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function post(Contract $contract, Envelope $envelope): void
    {
        // The negotiated figure if there is one, otherwise the headline value. A contract signed
        // with no value at all commits nothing, and the bridge treats that as nothing to say.
        $value = (float) ($contract->final_contract_value ?: $contract->contract_value ?: 0);

        if ($value <= 0) {
            return;
        }

        $result = $this->bridge->postCommitment(
            sourceType: 'contract',
            sourceId: (int) $contract->id,
            amount: $value,
            // Dated to the signature, not to today: a contract signed on the last day of a
            // financial year belongs to that year's budget, however long the job takes to run.
            when: $envelope->completed_at ? Carbon::parse($envelope->completed_at) : now(),
            scope: [
                'department_id' => $contract->owner_id ? $this->departmentIdFor((int) $contract->owner_id) : null,
                'expenditure_type' => 'opex',
            ],
            // One commitment per contract, enforced by the database. A re-signed or amended
            // contract cannot lock the funds twice.
            reference: 'contract:'.$contract->id,
            note: __('budget_management.sources.contract', ['ref' => $contract->contract_number ?: $contract->id]),
        );

        if (! $result->landed()) {
            // A signed contract that committed nothing is worth knowing about — it means the
            // company has an obligation its budget does not show.
            Log::warning('contracts: signed contract did not commit a budget', [
                'contract' => $contract->id,
                'outcome' => $result->outcome,
                'reason' => $result->reason,
            ]);
        }
    }

    /**
     * The department the contract owner belongs to.
     *
     * `company_unit_user_pivots.company_id` holds a *company unit* id despite its name — the
     * platform-wide quirk this join has to live with.
     */
    private function departmentIdFor(int $userId): ?int
    {
        $unitId = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $userId)
            ->where('u.type', 'department')
            ->value('u.id');

        return $unitId ? (int) $unitId : null;
    }

    /** The status as a plain string, whether the model casts it to an enum or not. */
    private function statusOf(Envelope $envelope): string
    {
        $status = $envelope->status;

        return $status instanceof EnvelopeStatus ? $status->value : (string) $status;
    }
}
