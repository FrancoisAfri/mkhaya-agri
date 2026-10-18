<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPayment;
use Illuminate\Support\Carbon;

/**
 * AR/AP ageing and statements. By construction the open sub-ledger equals the GL
 * control account — ageing() reports the tie-out so any drift is visible.
 */
class AgeingService
{
    /** Bucket upper bounds (days overdue). Everything beyond the last is "120+". */
    private const BUCKETS = [30, 60, 90, 120];

    public function __construct(private FinanceReportService $reports) {}

    /**
     * Ageing for receivable or payable, bucketed per party, with the GL tie-out.
     */
    /** @param  int|string|null  $companyId  a company id, FinanceReportService::UNASSIGNED, or null for every book */
    public function ageing(string $direction, ?string $asOf = null, int|string|null $companyId = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $asOfDate = Carbon::parse($asOf);
        $labels = ['current', '1-30', '31-60', '61-90', '91-120', '120+'];

        $invoices = FinInvoice::query()
            // BelongsToCompany hides company-less rows behind CompanyScope. The unassigned pass
            // exists precisely to look at them, so the scope is lifted for that one case.
            ->when($companyId === FinanceReportService::UNASSIGNED, fn ($q) => $q->withoutGlobalScopes())
            ->with('party')
            ->where('direction', $direction)->whereIn('status', ['open', 'part_paid'])
            ->tap(fn ($q) => FinanceReportService::scopeToCompany($q, 'company_id', $companyId))
            ->whereDate('invoice_date', '<=', $asOf)
            ->get();

        $rows = [];
        foreach ($invoices as $inv) {
            $out = $inv->outstanding();
            if ($out <= 0) {
                continue;
            }
            $bucket = $this->bucket($asOfDate->diffInDays(Carbon::parse($inv->due_date), false));
            $pid = $inv->party_id;
            /*
             * A party that will not resolve is a data problem, not a reason to lose the report.
             *
             * `FinParty` is company-scoped, so an invoice whose customer carries a different
             * company_id — or none, as rows written before the tenancy retrofit do — comes back
             * with a null relation. Reading ->name on it aborted the whole ageing run, which took
             * `finance:parity` down with it every morning and left nobody with an ageing report at
             * all because of one bad row.
             *
             * Named rather than skipped: the money is real and still owed, and a customer silently
             * missing from an ageing report is how a debt stops being chased.
             */
            $rows[$pid] ??= ['party_id' => $pid, 'party' => $inv->party?->name ?? "Unlinked customer #{$pid}",
                'current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '91-120' => 0, '120+' => 0, 'total' => 0];
            $rows[$pid][$bucket] = round($rows[$pid][$bucket] + $out, 2);
            $rows[$pid]['total'] = round($rows[$pid]['total'] + $out, 2);
        }

        $rows = array_values($rows);
        $totals = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '91-120' => 0, '120+' => 0, 'total' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] = round($totals[$k] + $r[$k], 2);
            }
        }

        $controlCode = (string) config($direction === 'receivable' ? 'finance.ar_control' : 'finance.ap_control');
        $glRow = $this->reports->accountBalances($companyId, $asOf)->firstWhere('code', $controlCode);
        $glBalance = round((float) ($glRow['balance'] ?? 0), 2);

        return [
            'direction' => $direction, 'as_of' => $asOf, 'labels' => $labels,
            'rows' => $rows, 'totals' => $totals,
            'gl_control_code' => $controlCode, 'gl_balance' => $glBalance,
            'ties' => abs($totals['total'] - $glBalance) < 0.01,
        ];
    }

    /** Days overdue → bucket label. Not-yet-due (negative) is "current". */
    private function bucket(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }
        foreach (self::BUCKETS as $i => $upper) {
            if ($daysOverdue <= $upper) {
                return ['1-30', '31-60', '61-90', '91-120'][$i];
            }
        }

        return '120+';
    }

    /**
     * Statement of account for a party: invoices and payments in date order with a
     * running balance (what the party owes, or is owed, cumulatively).
     */
    public function statement(int $partyId, ?string $from = null, ?string $to = null): array
    {
        $party = FinParty::findOrFail($partyId);
        $to = $to ?: now()->toDateString();
        $from = $from ?: now()->startOfYear()->toDateString();

        $entries = collect();
        foreach (FinInvoice::where('party_id', $partyId)->where('status', '!=', 'void')->get() as $inv) {
            $isCredit = in_array($inv->doc_type, ['credit_note', 'writeoff', 'debit_note'], true);
            $typeLabel = match ($inv->doc_type) {
                'writeoff' => 'Write-off',
                'debit_note' => 'Debit note',
                'credit_note' => 'Credit note',
                default => $inv->direction === 'receivable' ? 'Invoice' : 'Bill',
            };
            $entries->push(['date' => $inv->invoice_date->toDateString(), 'ref' => $inv->number, 'type' => $typeLabel,
                'charge' => $isCredit ? 0.0 : (float) $inv->total,
                'credit' => $isCredit ? (float) $inv->total : 0.0,
                'sort' => $inv->invoice_date->timestamp]);
        }
        foreach (FinPayment::where('party_id', $partyId)->get() as $pmt) {
            $entries->push(['date' => $pmt->payment_date->toDateString(), 'ref' => $pmt->number,
                'type' => $pmt->direction === 'receipt' ? 'Receipt' : 'Payment',
                'charge' => 0.0, 'credit' => (float) $pmt->amount, 'sort' => $pmt->payment_date->timestamp]);
        }

        $sorted = $entries->sortBy('sort')->values();
        $opening = 0.0;
        // Everything before the window folds into the opening balance.
        foreach ($sorted as $e) {
            if ($e['date'] < $from) {
                $opening = round($opening + $e['charge'] - $e['credit'], 2);
            }
        }

        $rows = [];
        $running = $opening;
        foreach ($sorted as $e) {
            if ($e['date'] < $from) {
                continue;
            }
            $running = round($running + $e['charge'] - $e['credit'], 2);
            $rows[] = ['date' => $e['date'], 'ref' => $e['ref'], 'type' => $e['type'],
                'charge' => $e['charge'], 'credit' => $e['credit'], 'balance' => $running];
        }

        return [
            'party' => ['id' => $party->id, 'name' => $party->name, 'code' => $party->code],
            'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows,
            'closing' => $rows ? end($rows)['balance'] : $opening,
        ];
    }
}
