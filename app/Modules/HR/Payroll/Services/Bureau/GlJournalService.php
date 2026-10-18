<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;

/**
 * Produces the general-ledger journal for a pay run — the balanced debit/credit
 * entries a finance team posts to their accounting system. Debits = credits by
 * construction (employer costs and the cross-check are derived from the run
 * totals + payslip aggregates).
 */
class GlJournalService
{
    /**
     * @return array{lines:array<int,array{account:string,debit:float,credit:float}>, total_debit:float, total_credit:float, balanced:bool}
     */
    public function forRun(BureauPayRun $run): array
    {
        $gross        = (float) $run->total_gross;
        $net          = (float) $run->total_net;
        $paye         = (float) $run->total_paye;
        $uifEmployee  = (float) $run->total_uif_employee;
        $uifEmployer  = (float) $run->total_uif_employer;
        $sdl          = (float) $run->total_sdl;
        $eti          = (float) $run->total_eti;
        $employerContrib = (float) $run->payslips()->sum('employer_contributions');

        // Employee deductions other than PAYE/UIF (retirement, medical, garnishees…).
        $employeeDeductions = round($gross - $net - $paye - $uifEmployee, 2);

        $lines = [];
        $dr = function ($account, $amount) use (&$lines) {
            if ($amount > 0) {
                $lines[] = ['account' => $account, 'debit' => round($amount, 2), 'credit' => 0.0];
            }
        };
        $cr = function ($account, $amount) use (&$lines) {
            if ($amount > 0) {
                $lines[] = ['account' => $account, 'debit' => 0.0, 'credit' => round($amount, 2)];
            }
        };

        // Debits — expenses + the ETI offset against the SARS liability.
        $dr('Salaries & wages expense', $gross);
        $dr('Employer contributions expense', $employerContrib + $uifEmployer + $sdl);
        $dr('SARS payable (ETI offset)', $eti);

        // Credits — net pay + statutory & deduction liabilities + ETI income.
        $cr('Bank - net salaries', $net);
        $cr('PAYE payable (SARS)', $paye);
        $cr('UIF payable', $uifEmployee + $uifEmployer);
        $cr('SDL payable', $sdl);
        $cr('Employer contributions payable', $employerContrib);
        $cr('Employee deductions payable', $employeeDeductions);
        $cr('ETI income', $eti);

        $totalDebit  = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        return [
            'lines'        => $lines,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    public function csv(BureauPayRun $run): string
    {
        $journal = $this->forRun($run);
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['Payroll journal', $run->company->name, $run->name]);
        fputcsv($h, ['Account', 'Debit', 'Credit']);
        foreach ($journal['lines'] as $l) {
            fputcsv($h, [$l['account'], number_format($l['debit'], 2, '.', ''), number_format($l['credit'], 2, '.', '')]);
        }
        fputcsv($h, ['TOTAL', number_format($journal['total_debit'], 2, '.', ''), number_format($journal['total_credit'], 2, '.', '')]);
        rewind($h);

        return stream_get_contents($h);
    }
}
