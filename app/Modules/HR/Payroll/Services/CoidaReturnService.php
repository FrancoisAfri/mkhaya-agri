<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\CoidaRegistration;
use App\Modules\HR\Payroll\Models\CoidaReturn;
use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prepares a Return of Earnings.
 *
 * The arithmetic that matters is the cap: the Fund assesses each employee's earnings only up to an
 * annual ceiling, so an employer with three directors on R2 million each pays on three times the
 * ceiling, not on six million. Capping the *total* instead of each employee — which is the obvious
 * mistake — understates a large payroll and overstates a small one.
 *
 * Works for both engines. A bureau prepares one per client; an in-house payroll prepares its own.
 */
class CoidaReturnService
{
    public function __construct(private readonly LabourRates $rates)
    {
    }

    /**
     * Build (or rebuild) the return for an assessment year.
     *
     * Rebuilding a draft is expected — payroll for the year is still moving while it is prepared.
     * A return already submitted is never overwritten; that one is a record of what was filed.
     */
    public function prepare(?BureauCompany $client, int $assessmentYear, ?float $estimate = null): CoidaReturn
    {
        $existing = CoidaReturn::query()
            ->where('bureau_company_id', $client?->id)
            ->where('assessment_year', $assessmentYear)
            ->first();

        if ($existing && $existing->status === CoidaReturn::SUBMITTED) {
            throw ValidationException::withMessages([
                'return' => "The {$assessmentYear} return has already been submitted. Prepare a new year, or reopen that one deliberately.",
            ]);
        }

        [$from, $to] = $this->yearBounds($assessmentYear);
        $ceiling = $this->rates->coidaCeiling($to);

        if ($ceiling === null || $ceiling <= 0) {
            throw ValidationException::withMessages([
                'return' => "No COIDA earnings ceiling is loaded for {$assessmentYear}. Capture it on the labour rates before preparing the return.",
            ]);
        }

        $employees = $client
            ? $this->bureauEarnings($client, $from, $to)
            : $this->inHouseEarnings($from, $to);

        $actual = 0.0;
        $leviable = 0.0;

        foreach ($employees as &$row) {
            // The cap is per employee, per year. This is the whole calculation.
            $row['leviable'] = round(min($row['actual'], $ceiling), 2);
            $row['capped'] = $row['actual'] > $ceiling;

            $actual += $row['actual'];
            $leviable += $row['leviable'];
        }
        unset($row);

        $registration = CoidaRegistration::query()
            ->where('bureau_company_id', $client?->id)
            ->first();

        $rate = (float) ($registration->assessment_rate_per_100 ?? 0);

        $payload = [
            'bureau_company_id'       => $client?->id,
            'registration_id'         => $registration?->id,
            'assessment_year'         => $assessmentYear,
            'ceiling_applied'         => $ceiling,
            'total_actual_earnings'   => round($actual, 2),
            'total_leviable_earnings' => round($leviable, 2),
            // The Fund quotes its rate per R100 of leviable earnings.
            'assessment'              => round($leviable / 100 * $rate, 2),
            'estimated_earnings'      => $estimate ?? round($leviable, 2),
            'employee_count'          => count($employees),
            'employees'               => array_values($employees),
            'status'                  => CoidaReturn::DRAFT,
            'prepared_by'             => Auth::id(),
            'prepared_at'             => now(),
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->refresh();
        }

        return CoidaReturn::create($payload);
    }

    /** March to February, the same year boundary the tax year uses. */
    public function yearBounds(int $assessmentYear): array
    {
        return [
            Carbon::create($assessmentYear - 1, 3, 1)->startOfDay(),
            Carbon::create($assessmentYear, 2, 1)->endOfMonth()->endOfDay(),
        ];
    }

    /**
     * A bureau client's earnings for the year, from finalised runs only.
     *
     * A draft run is a figure somebody is still working on; including it would put a number in a
     * statutory return that nobody has approved.
     */
    private function bureauEarnings(BureauCompany $client, Carbon $from, Carbon $to): array
    {
        return DB::table('bureau_payslips as bp')
            ->join('bureau_pay_runs as r', 'r.id', '=', 'bp.bureau_pay_run_id')
            ->join('bureau_employees as e', 'e.id', '=', 'bp.bureau_employee_id')
            ->where('r.bureau_company_id', $client->id)
            ->whereIn('r.status', ['finalised', 'paid', 'completed'])
            ->whereDate('r.period_end', '>=', $from)
            ->whereDate('r.period_end', '<=', $to)
            ->groupBy('e.id', 'e.first_name', 'e.last_name', 'e.employee_number', 'e.id_number')
            ->selectRaw("e.id, TRIM(CONCAT(e.first_name,' ',COALESCE(e.last_name,''))) AS name,
                         e.employee_number, e.id_number, COALESCE(SUM(bp.gross),0) AS actual")
            ->get()
            ->map(fn ($r) => [
                'employee_id' => (int) $r->id,
                'name'        => $r->name,
                'number'      => $r->employee_number,
                'id_number'   => $r->id_number,
                'actual'      => round((float) $r->actual, 2),
            ])
            ->all();
    }

    /** The same, for the in-house payroll. */
    private function inHouseEarnings(Carbon $from, Carbon $to): array
    {
        return DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'p.employee_id')
            ->where('r.status', 'released')
            ->whereDate('r.period_end', '>=', $from)
            ->whereDate('r.period_end', '<=', $to)
            ->groupBy('p.employee_id', 'hp.first_name', 'hp.surname', 'hp.employee_number', 'hp.id_number')
            ->selectRaw("p.employee_id AS id,
                         TRIM(CONCAT(COALESCE(hp.first_name,''),' ',COALESCE(hp.surname,''))) AS name,
                         hp.employee_number, hp.id_number, COALESCE(SUM(p.gross_pay),0) AS actual")
            ->get()
            ->map(fn ($r) => [
                'employee_id' => (int) $r->id,
                'name'        => trim($r->name) ?: "Employee #{$r->id}",
                'number'      => $r->employee_number,
                'id_number'   => $r->id_number,
                'actual'      => round((float) $r->actual, 2),
            ])
            ->all();
    }

    /** Lock the return. After this it is a record of what was filed. */
    public function markSubmitted(CoidaReturn $return): CoidaReturn
    {
        $return->update([
            'status'       => CoidaReturn::SUBMITTED,
            'submitted_at' => now(),
        ]);

        return $return->refresh();
    }

    /**
     * The return as a CSV, one line per employee.
     *
     * Deliberately not a fixed-format submission file: the Fund's own layout changes on their
     * schedule, and the file-specification engine is where a layout belongs once the current one is
     * in hand. This is what a person checks and uploads in the meantime.
     */
    public function toCsv(CoidaReturn $return): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Employee', 'Employee number', 'ID number', 'Actual earnings', 'Leviable earnings', 'Capped']);

        foreach ($return->employees ?? [] as $row) {
            fputcsv($handle, [
                $row['name'] ?? '',
                $row['number'] ?? '',
                $row['id_number'] ?? '',
                number_format((float) ($row['actual'] ?? 0), 2, '.', ''),
                number_format((float) ($row['leviable'] ?? 0), 2, '.', ''),
                ! empty($row['capped']) ? 'yes' : 'no',
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Total actual', number_format((float) $return->total_actual_earnings, 2, '.', '')]);
        fputcsv($handle, ['Total leviable', number_format((float) $return->total_leviable_earnings, 2, '.', '')]);
        fputcsv($handle, ['Ceiling applied', number_format((float) $return->ceiling_applied, 2, '.', '')]);
        fputcsv($handle, ['Assessment', number_format((float) $return->assessment, 2, '.', '')]);

        rewind($handle);

        return stream_get_contents($handle);
    }
}
