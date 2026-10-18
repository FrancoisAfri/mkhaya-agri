<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Finance\Accounting\Services\FinancePoster;
use App\Modules\Operations\ProjectManagement\Models\CompletionRecord;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A certified payment certificate, in the books.
 *
 * Certification is the accounting event, not payment and not invoicing: it is the moment the
 * contractor's work becomes money owed. The claim already carries every figure a quantity surveyor
 * needs — work to date, materials on site, variations, previously certified, retention held and
 * released, net due — and none of it reached the ledger, so a project's committed cost was complete
 * on the screen and absent from the accounts.
 *
 * ── Which way the money runs ────────────────────────────────────────────────
 *
 * This one is easy to get backwards, and getting it backwards puts the entry on the wrong side of
 * the balance sheet. `ProgressClaim` is "how a contractor gets paid" — *we* certify what a
 * subcontractor claims from *us*. So the certified value is a cost we have incurred and the amount
 * owed is a payable. It is not revenue and the retention is not an asset.
 *
 * ── Retention is a liability we hold, not a debt we owe yet ─────────────────
 *
 * Retention is money withheld from the contractor against defects. It is owed eventually, but not
 * now and not on the same terms, so it does not belong in accounts payable next to the amount due
 * this month — that would say the business owes more today than it does, and would age the wrong
 * figure. It sits in its own liability account and moves across when released.
 *
 * The platform's chart carries `1250 Retention Receivable`, which is the opposite case: retention a
 * *client* holds from us. There is no retention-payable account, and inventing one in every
 * tenant's chart is not a decision that belongs in a code change. So the key is mapped and nothing
 * is seeded: until somebody chooses the account, this posts nothing and says so — the same
 * fail-safe every other posting in this module follows.
 *
 * ── Gross, not net ──────────────────────────────────────────────────────────
 *
 * The cost is the work certified this period, before retention comes off. Posting the net would
 * understate what the job has cost by exactly the amount being held back, which is the figure the
 * whole certificate exists to track.
 */
class ClaimLedgerPoster
{
    /** Cost of sales, which every chart on this platform carries. */
    private const STANDARD_CHART_COST = '5000';

    /** Accounts payable — what is due to the contractor now. */
    private const STANDARD_CHART_PAYABLE = '2100';

    public function __construct(
        private readonly FinancePoster $poster,
        private readonly AccountMap $accounts,
    ) {
    }

    /**
     * Retention released, moving from money held to money owed.
     *
     * The other half of the hold, and the half that is easy to leave unbuilt because it happens
     * months later — typically at practical completion and again at the end of the defects period.
     * Until it posts, the retention liability accumulates and never comes down, so the balance
     * sheet says the business is holding money it has long since paid out.
     *
     * Dr retention, Cr payables: nothing about the total owed to the contractor changes, only
     * whether it is due now. That is why this is not a cost — the cost was taken when the work was
     * certified, and taking it again here would count the same work twice.
     */
    public function postRelease(CompletionRecord $record): ?string
    {
        if (! Schema::hasTable('fin_postings')) {
            return null;
        }

        $released = round((float) $record->retention_released, 2);

        if ($released <= 0) {
            return null;
        }

        $payable = $this->accounts->codeFor('project_management', 'claim.payable', self::STANDARD_CHART_PAYABLE);
        $retention = $this->accounts->codeFor('project_management', 'claim.retention_payable');

        if ($payable === null || $retention === null) {
            Log::info('pm: released retention not posted, accounts are not mapped', [
                'completion' => $record->uuid,
                'payable'    => $payable,
                'retention'  => $retention,
            ]);

            return null;
        }

        try {
            $posting = $this->poster->emit(new FinancialEvent(
                sourceModule: 'project_management',
                eventType: 'pm.retention.released',
                debits: [[
                    'account'    => $retention,
                    'amount'     => $released,
                    'narration'  => __('project_management.site_records.retention_release_narration'),
                    'dimensions' => ['project' => $record->project?->code],
                ]],
                credits: [[
                    'account'   => $payable,
                    'amount'    => $released,
                    'narration' => __('project_management.site_records.retention_release_narration'),
                ]],
                eventDate: $record->certified_on ?? now(),
                currency: $record->currency ?: 'ZAR',
                sourceRef: 'pm_retention:' . $record->uuid,
                description: 'Retention released on ' . ($record->kind ?? 'completion'),
                metadata: ['project' => $record->project?->code, 'kind' => $record->kind],
                idempotencyKey: 'pm_retention:' . $record->uuid,
            ));

            return (string) ($posting->source_ref ?: $record->uuid);
        } catch (\Throwable $e) {
            // A ledger problem must never undo a completion certificate somebody has signed.
            Log::warning('pm: ledger posting failed for released retention', [
                'completion' => $record->uuid,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function post(ProgressClaim $claim): ?string
    {
        if (! Schema::hasTable('fin_postings')) {
            return null;
        }

        $netDue = round((float) $claim->net_due, 2);
        $retention = round((float) $claim->retention_held, 2);
        $gross = round($netDue + $retention, 2);

        if ($gross <= 0) {
            return null;
        }

        $cost = $this->accounts->codeFor('project_management', 'claim.cost', self::STANDARD_CHART_COST);
        $payable = $this->accounts->codeFor('project_management', 'claim.payable', self::STANDARD_CHART_PAYABLE);

        // Only needed when something is actually being retained; a certificate with no retention
        // must not be blocked by an account nobody has had reason to map.
        $retentionAccount = $retention > 0
            ? $this->accounts->codeFor('project_management', 'claim.retention_payable')
            : null;

        if ($cost === null || $payable === null || ($retention > 0 && $retentionAccount === null)) {
            Log::info('pm: certified claim not posted, accounts are not mapped', [
                'claim'     => $claim->uuid,
                'cost'      => $cost,
                'payable'   => $payable,
                'retention' => $retentionAccount,
                'fix'       => 'Map claim.cost, claim.payable and claim.retention_payable under Accounting.',
            ]);

            return null;
        }

        $credits = [[
            'account'   => $payable,
            'amount'    => $netDue,
            'narration' => __('project_management.site_records.claim_narration', ['number' => $claim->claim_number]),
        ]];

        if ($retention > 0) {
            $credits[] = [
                'account'   => $retentionAccount,
                'amount'    => $retention,
                'narration' => __('project_management.site_records.retention_narration'),
            ];
        }

        try {
            $posting = $this->poster->emit(new FinancialEvent(
                sourceModule: 'project_management',
                eventType: 'pm.claim.certified',
                debits: [[
                    'account'   => $cost,
                    'amount'    => $gross,
                    'narration' => __('project_management.site_records.claim_narration', ['number' => $claim->claim_number]),
                    // The job travels with the entry, so the ledger can answer "what has this cost?"
                    'dimensions' => ['project' => $claim->project?->code],
                ]],
                credits: $credits,
                eventDate: $claim->certified_at ?? now(),
                currency: $claim->currency ?: 'ZAR',
                sourceRef: 'pm_claim:' . $claim->uuid,
                description: 'Certified progress claim #' . $claim->claim_number,
                metadata: [
                    'project'        => $claim->project?->code,
                    'claim_number'   => $claim->claim_number,
                    'retention_held' => $retention,
                ],
                // Certifying twice must post once.
                idempotencyKey: 'pm_claim:' . $claim->uuid,
            ));

            return (string) ($posting->source_ref ?: $claim->uuid);
        } catch (\Throwable $e) {
            // A ledger problem must never undo a certification somebody has signed.
            Log::warning('pm: ledger posting failed for a certified claim', [
                'claim' => $claim->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
