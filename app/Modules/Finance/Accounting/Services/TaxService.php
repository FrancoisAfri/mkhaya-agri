<?php

namespace App\Modules\Finance\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tax engine (Phase 5.4). Computes a VAT201-shaped return from the ledger: output
 * tax from the VAT-output control account, input tax from the VAT-input control
 * account, and the net payable/refundable to the authority.
 */
class TaxService
{
    /** Net movement (natural side) on an account between two dates. */
    private function movement(string $accountCode, string $from, string $to, string $side, ?int $companyId): float
    {
        $row = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $accountCode)
            ->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw('COALESCE(SUM(l.base_debit),0) d, COALESCE(SUM(l.base_credit),0) c')->first();

        $d = (float) ($row->d ?? 0);
        $c = (float) ($row->c ?? 0);

        return round($side === 'credit' ? $c - $d : $d - $c, 2);
    }

    /** Total movement across a set of account types (income or expense) for context boxes. */
    private function typeMovement(string $type, string $side, string $from, string $to, ?int $companyId): float
    {
        $row = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', $type)
            ->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw('COALESCE(SUM(l.base_debit),0) d, COALESCE(SUM(l.base_credit),0) c')->first();

        $d = (float) ($row->d ?? 0);
        $c = (float) ($row->c ?? 0);

        return round($side === 'credit' ? $c - $d : $d - $c, 2);
    }

    public function vat201(string $from, string $to, ?int $companyId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to   = Carbon::parse($to)->toDateString();

        $outputTax = $this->movement(config('finance.vat_output'), $from, $to, 'credit', $companyId); // liability, credit-normal
        $inputTax  = $this->movement(config('finance.vat_input'), $from, $to, 'debit', $companyId);    // asset, debit-normal
        $net       = round($outputTax - $inputTax, 2);
        $rate      = 15;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'boxes'  => [
                // VAT201-shaped summary (SA default).
                ['field' => '1',  'label' => 'Standard-rated supplies (excl. VAT)', 'amount' => round($outputTax / ($rate / 100), 2)],
                ['field' => '4',  'label' => 'Output tax',                          'amount' => $outputTax],
                ['field' => '14', 'label' => 'Input tax',                           'amount' => $inputTax],
                ['field' => '20', 'label' => $net >= 0 ? 'VAT payable to SARS' : 'VAT refundable', 'amount' => abs($net)],
            ],
            'output_tax'    => $outputTax,
            'input_tax'     => $inputTax,
            'net'           => $net,
            'is_payable'    => $net >= 0,
            'total_sales'   => $this->typeMovement('income', 'credit', $from, $to, $companyId),
            'total_purchases' => $this->typeMovement('expense', 'debit', $from, $to, $companyId),
        ];
    }
}
