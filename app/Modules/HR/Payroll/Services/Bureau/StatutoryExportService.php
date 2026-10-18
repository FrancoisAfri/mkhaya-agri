<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;

/**
 * SARS statutory exports for a bureau company: the monthly EMP201 declaration
 * (per run) and the annual IRP5/IT3(a) tax-certificate data (per tax year),
 * tagged with the SARS source codes used for e@syFile capture. Aggregates the
 * company's finalised payslips.
 */
class StatutoryExportService
{
    /** EMP201 monthly declaration for a run as CSV. */
    public function emp201Csv(BureauPayRun $run): string
    {
        $paye = (float) $run->total_paye;
        $uif  = (float) $run->total_uif_employee + (float) $run->total_uif_employer;
        $sdl  = (float) $run->total_sdl;
        $eti  = (float) $run->total_eti;

        $rows = [
            ['EMP201 Declaration', ''],
            ['Company', $run->company->name],
            ['PAYE reference', $run->company->paye_reference],
            ['Period', $run->period_end?->format('Y-m')],
            ['', ''],
            ['PAYE (4102)', number_format($paye, 2, '.', '')],
            ['UIF (4141)', number_format($uif, 2, '.', '')],
            ['SDL (4142)', number_format($sdl, 2, '.', '')],
            ['ETI claimed', number_format($eti, 2, '.', '')],
            ['Total payable to SARS', number_format($paye + $uif + $sdl - $eti, 2, '.', '')],
        ];

        return $this->toCsv($rows);
    }

    /**
     * IRP5/IT3(a) tax-certificate data for every employee in a tax year,
     * aggregating all finalised runs. One row per employee with SARS codes.
     */
    public function irp5Csv(BureauCompany $company, int $taxYear): string
    {
        $rows = [[
            'Certificate type', 'Employee', 'ID number', 'Tax number',
            'Gross remuneration (3699)', 'Income (3601)', 'PAYE (4102)',
            'UIF (4141)', 'Retirement (4001)', 'Medical aid (4005)',
        ]];

        foreach ($this->certificates($company, $taxYear) as $c) {
            $rows[] = [
                $c['type'], $c['name'], $c['id_number'], $c['tax_number'],
                number_format($c['gross'], 2, '.', ''),
                number_format($c['gross'], 2, '.', ''),
                number_format($c['paye'], 2, '.', ''),
                number_format($c['uif'], 2, '.', ''),
                number_format($c['retirement'], 2, '.', ''),
                number_format($c['medical'], 2, '.', ''),
            ];
        }

        return $this->toCsv($rows);
    }

    /**
     * e@syFile Employer import file for the company's tax year. Follows the SARS
     * PAYE BRS code,value structure (header block, then one certificate per
     * employee delimited by 9999). The user imports this into e@syFile, which
     * runs the EMP501 reconciliation and submits to SARS. NOTE: the exact code
     * set is version-specific — validate against the current SARS PAYE BRS
     * before production filing.
     */
    public function easyFileImport(BureauCompany $company, int $taxYear): string
    {
        $period = $taxYear.'02'; // bi-annual recon period ending Feb (full year)
        $lines = [];

        // Header block (2xxx codes).
        $lines[] = '2010,'.$taxYear;                                  // transaction year
        $lines[] = '2020,'.($company->paye_reference ?: '');          // PAYE reference
        $lines[] = '2030,'.$this->clean($company->name, 90);         // employer name
        $lines[] = '2025,'.$period;                                   // period of reconciliation

        foreach ($this->certificates($company, $taxYear) as $c) {
            $cents = fn ($v) => (int) round($v * 100);
            $lines[] = '3010,'.($c['id_number'] ? 'A' : 'B');         // nature of person (A = SA ID)
            $lines[] = '3015,'.($c['type'] === 'IRP5' ? 'IRP5' : 'IT3a');
            $lines[] = '3025,'.$taxYear;                              // year of assessment
            $lines[] = '3026,'.$period;                              // period
            $lines[] = '3030,'.$this->clean($c['surname'], 120);
            $lines[] = '3040,'.$this->clean($c['first_names'], 90);
            $lines[] = '3050,'.$this->clean($c['initials'], 5);
            $lines[] = '3060,'.($c['id_number'] ?: '');
            $lines[] = '3100,'.($c['tax_number'] ?: '');
            $lines[] = '3696,'.$cents($c['gross']);                  // non-taxable? (kept 0 here)
            $lines[] = '3601,'.$cents($c['gross']);                  // income
            $lines[] = '3699,'.$cents($c['gross']);                  // gross remuneration
            $lines[] = '4001,'.$cents($c['retirement']);            // retirement contributions
            $lines[] = '4005,'.$cents($c['medical']);              // medical contributions
            $lines[] = '4102,'.$cents($c['paye']);                  // PAYE
            $lines[] = '4141,'.$cents($c['uif']);                   // UIF (employee + employer)
            $lines[] = '4149,'.$cents($c['paye'] + $c['uif']);     // total tax + levies
            $lines[] = '9999,';                                      // end of certificate
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<int,array<string,mixed>> per-employee aggregated certificate data */
    private function certificates(BureauCompany $company, int $taxYear): array
    {
        $runIds = $company->payRuns()
            ->where('tax_year', $taxYear)
            ->where('status', BureauPayRun::STATUS_FINALISED)
            ->pluck('id');

        $out = [];
        foreach (BureauPayslip::whereIn('bureau_pay_run_id', $runIds)->with('employee')->get()->groupBy('bureau_employee_id') as $slips) {
            $e = $slips->first()->employee;
            if (! $e) {
                continue;
            }
            $gross = $slips->sum(fn (BureauPayslip $s) => (float) $s->gross);
            $paye  = $slips->sum(fn (BureauPayslip $s) => (float) $s->paye);
            $names = trim($e->first_name);

            $out[] = [
                'type'        => $paye > 0 ? 'IRP5' : 'IT3(a)',
                'name'        => $e->fullName(),
                'surname'     => $e->last_name,
                'first_names' => $names,
                'initials'    => strtoupper(substr($names, 0, 1)),
                'id_number'   => $e->id_number,
                'tax_number'  => $e->tax_number,
                'gross'       => $gross,
                'paye'        => $paye,
                'uif'         => $slips->sum(fn (BureauPayslip $s) => (float) $s->uif_employee + (float) $s->uif_employer),
                'retirement'  => $slips->sum(fn (BureauPayslip $s) => (float) $s->retirement_deduction),
                'medical'     => (float) $e->medical_contribution * $slips->count(),
            ];
        }

        return $out;
    }

    private function clean(?string $v, int $len): string
    {
        return substr(preg_replace('/[\r\n,]+/', ' ', (string) $v), 0, $len);
    }

    private function toCsv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($h, $row);
        }
        rewind($h);

        return stream_get_contents($h);
    }
}
