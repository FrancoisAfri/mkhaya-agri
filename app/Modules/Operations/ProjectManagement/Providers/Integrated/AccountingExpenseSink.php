<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Finance\Accounting\Services\FinancePoster;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ExpenseSink;
use Illuminate\Support\Facades\Log;

/**
 * Approved project costs, posted to the general ledger (`finance.postings`).
 *
 * PM does not invent a posting format. `FinancialEvent` is the canonical shape every operational
 * module on this platform emits and the Ledger consumes, and `FinancePoster::emit()` is the outbox
 * that carries it — so a project cost arrives in the books by the same route as a payroll run, is
 * retried by the same worker, and is reconciled by the same reports.
 *
 * ── Which accounts ──────────────────────────────────────────────────────────
 *
 * Debit an expense account by cost category, credit accounts payable. Both are looked up by code in
 * `fin_accounts` and both must exist and be postable; if either is missing the posting is *not*
 * attempted, because a journal against a guessed account is worse than no journal — it is a wrong
 * entry somebody has to find and reverse.
 *
 * ── When posting fails ──────────────────────────────────────────────────────
 *
 * The approval still stands. A cost is approved by a person on an operational screen; whether the
 * ledger accepted the journal is Finance's problem and must never roll back somebody's approval.
 * Failures are logged and the entry keeps its PM reference, so it is exportable and re-postable.
 */
class AccountingExpenseSink implements ExpenseSink
{
    /**
     * The last resort, and the reason this class posted nothing for anybody on the standard chart.
     *
     * The codes this used to insist on — 5100 material, 5200 plant, 5300 subcontractor, 5400
     * expense, 5410 travel — are not in `FinanceGlSeeder`'s chart of accounts and never have been.
     * Its cost-of-sales account is 5000. So every approved project cost took the "chart of accounts
     * lacks the expected codes" branch, logged it once, and never reached the books. The fail-safe
     * was right; it was guarding a door that opened onto nothing.
     *
     * Resolution now runs tenant mapping → platform default → this. The platform defaults still
     * carry the historic 5100–5410, so a tenant who *does* have those accounts keeps posting
     * exactly where they always did; only when those do not resolve does it fall through to here,
     * which is the account the standard chart actually has.
     *
     * Cost of sales for every category, because that is what the standard chart offers. The
     * category still travels in the posting's metadata, and a tenant wanting a separate account per
     * category now has somewhere to say so.
     */
    private const STANDARD_CHART_EXPENSE = '5000';

    /** Accounts payable: the other side of every unpaid project cost, and in every chart. */
    private const PAYABLES_ACCOUNT = '2100';

    /** The fallback account, exposed so tests assert against the constant rather than a copy of it. */
    public static function standardChartExpense(): string
    {
        return self::STANDARD_CHART_EXPENSE;
    }

    public function post(array $expense): string
    {
        $reference = 'PM-EXP-' . strtoupper(substr((string) ($expense['uuid'] ?? uniqid()), 0, 8));

        $category = $expense['category'] ?? 'other';
        $map = app(AccountMap::class);

        // Mapped, defaulted, or the constant — and postable, which AccountMap checks as part of
        // answering rather than leaving to be forgotten here.
        $debit = $map->codeFor('project_management', 'cost.' . $category, self::STANDARD_CHART_EXPENSE);

        $credit = $map->codeFor('project_management', 'payables', self::PAYABLES_ACCOUNT);

        if ($debit === null || $credit === null) {
            // The tenant's chart of accounts does not carry these codes. Say so once, plainly, and
            // leave the cost recorded in PM — where it still counts towards actual spend and is
            // still exportable — rather than posting it somewhere it does not belong.
            Log::info('pm: no ledger posting, no postable account mapped for this cost', [
                'expense'  => $expense['uuid'] ?? null,
                'category' => $category,
                'debit'    => $debit,
                'credit'   => $credit,
                'fix'      => 'Map it under Accounting → Ledger accounts.',
            ]);

            return $reference;
        }

        $amount = round((float) ($expense['amount'] ?? 0), 2);

        try {
            $posting = app(FinancePoster::class)->emit(new FinancialEvent(
                sourceModule: 'project_management',
                eventType: 'pm.cost.approved',
                debits: [[
                    'account'   => $debit,
                    'amount'    => $amount,
                    'narration' => $expense['description'] ?? 'Project cost',
                    // The project code travels as a dimension, which is what makes the ledger
                    // answer "what has this job cost?" without PM being asked.
                    'dimensions' => ['project' => $expense['project'] ?? null],
                ]],
                credits: [[
                    'account'   => $credit,
                    'amount'    => $amount,
                    'narration' => $expense['description'] ?? 'Project cost',
                ]],
                eventDate: $expense['incurred_on'] ?? null,
                currency: $expense['currency'] ?? 'ZAR',
                sourceRef: 'pm_cost:' . ($expense['uuid'] ?? ''),
                description: (string) ($expense['description'] ?? ''),
                metadata: [
                    'project'  => $expense['project'] ?? null,
                    'category' => $expense['category'] ?? null,
                ],
                // Approving the same cost twice must post one journal, not two. The outbox
                // deduplicates on this key.
                idempotencyKey: 'pm_cost:' . ($expense['uuid'] ?? $reference),
            ));

            return (string) ($posting->source_ref ?: $reference);
        } catch (\Throwable $e) {
            // Never let a ledger problem undo an operational approval.
            Log::warning('pm: ledger posting failed for an approved cost', [
                'expense' => $expense['uuid'] ?? null,
                'error'   => $e->getMessage(),
            ]);

            return $reference;
        }
    }

    /**
     * Whether an approved cost will actually reach the books.
     *
     * Asked by the screens, so none of them can imply the ledger moved when it did not. Payables is
     * the honest single test: every project cost credits it, so if that is unmapped or unpostable
     * nothing here posts at all, whatever the expense side says.
     */
    public function postsToLedger(): bool
    {
        return app(AccountMap::class)->has('project_management', 'payables', self::PAYABLES_ACCOUNT);
    }
}
