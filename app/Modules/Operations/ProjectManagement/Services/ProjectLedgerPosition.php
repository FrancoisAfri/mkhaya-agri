<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * What a project's clients actually owe, according to the ledger.
 *
 * Project Management could say what it had billed. It could never say what had been *paid*, because
 * a project invoice had no financial life after it was issued — no paid state, no receipt, no
 * ageing. So "outstanding" on a project screen meant one thing and "outstanding" to the person
 * chasing the money meant another, and only one of them was in the books.
 *
 * ── Two different "outstanding"s, kept apart ────────────────────────────────
 *
 * This is the distinction that matters and it is easy to collapse by accident:
 *
 *   `professional_fee_outstanding` on the monthly report is **fee left to bill** — the estimated
 *   fee less what has been invoiced. It is a forecasting number and it has nothing to do with debt.
 *
 *   `outstanding` here is **money the client owes** — invoiced and not yet paid. It is a debtors
 *   number and it comes from the receivable.
 *
 * A project can have nothing left to bill and a large debtor balance, or the reverse. Adding them,
 * or letting one label do for both, is how a report tells somebody a job is finished when the money
 * is still out.
 *
 * ── Scoped to this project's own invoices ───────────────────────────────────
 *
 * Through `pm_invoices.ledger_invoice_id`, not by asking what the client owes altogether. The same
 * client may be billed on four projects and from other modules entirely; attributing all of it here
 * would make every one of those projects look four times as exposed as it is.
 */
class ProjectLedgerPosition
{
    /**
     * @return array{available:bool, currency:?string, invoiced:float, paid:float,
     *               outstanding:float, overdue:float, oldest_unpaid_days:?int, invoices:int}
     */
    public function forProject(Project $project): array
    {
        $empty = [
            'available' => false, 'currency' => $project->currency,
            'invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0,
            'oldest_unpaid_days' => null, 'invoices' => 0,
        ];

        // Guarded on the table: PM must keep working on a tenant that never bought Accounting.
        if (! Schema::hasTable('fin_invoices')) {
            return $empty;
        }

        $ledgerIds = Invoice::forProject($project->id)
            ->whereNotNull('ledger_invoice_id')
            ->pluck('ledger_invoice_id')
            ->all();

        if ($ledgerIds === []) {
            return $empty;
        }

        $receivables = FinInvoice::query()
            ->whereIn('id', $ledgerIds)
            // A cancelled receivable is not a debt and must not be counted as one.
            ->where('status', '!=', 'void')
            ->get(['id', 'total', 'amount_paid', 'due_date', 'currency', 'status']);

        if ($receivables->isEmpty()) {
            return $empty;
        }

        $today = Carbon::today();
        $overdue = 0.0;
        $oldestDays = null;

        foreach ($receivables as $receivable) {
            $unpaid = round((float) $receivable->total - (float) $receivable->amount_paid, 2);

            if ($unpaid <= 0) {
                continue;
            }

            if ($receivable->due_date && $receivable->due_date->lt($today)) {
                $overdue += $unpaid;

                $days = (int) $receivable->due_date->diffInDays($today);
                $oldestDays = max($oldestDays ?? 0, $days);
            }
        }

        return [
            'available'   => true,
            // The receivables' own currency, not the project's: it is the ledger being reported.
            'currency'    => $receivables->first()->currency ?: $project->currency,
            'invoiced'    => round((float) $receivables->sum('total'), 2),
            'paid'        => round((float) $receivables->sum('amount_paid'), 2),
            'outstanding' => round((float) $receivables->sum('total') - (float) $receivables->sum('amount_paid'), 2),
            'overdue'     => round($overdue, 2),
            'oldest_unpaid_days' => $oldestDays,
            'invoices'    => $receivables->count(),
        ];
    }
}
