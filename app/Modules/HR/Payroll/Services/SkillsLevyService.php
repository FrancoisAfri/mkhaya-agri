<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the Skills Development Levy paid, and what can be claimed back against it.
 *
 * Payroll calculates SDL and pays it over with the EMP201 every month. The WSP and ATR that
 * reclaim a fifth of it live in the LMS module, which knows about training but has never known
 * what was actually levied — so an employer submitting a Workplace Skills Plan had no figure to
 * check the grant against, and nobody could answer "how much of last year's levy did we get back?".
 *
 * The two halves are joined here. Payroll owns the levy because payroll paid it; this service says
 * what was paid, what the mandatory grant is worth against it, and how much of that has actually
 * been recovered.
 *
 * The levy year runs April to March, matching the WSP cycle, and not the tax year.
 */
class SkillsLevyService
{
    /** SETA mandatory grant: 20% of the levy paid, for an employer that submits on time. */
    private const MANDATORY_GRANT_RATE = 0.20;

    /** Below this annual payroll an employer is exempt and pays no levy at all. */
    private const EXEMPTION_THRESHOLD = 500000.0;

    /**
     * @return array{
     *   year:int, period:array{from:string,to:string}, leviable_payroll:float,
     *   levy_paid:float, exempt:bool, mandatory_grant:float, grant_claimed:float,
     *   grant_outstanding:float, submitted:bool, deadline:string, findings:string[]
     * }
     */
    public function forYear(int $year, ?int $companyId = null): array
    {
        [$from, $to] = $this->yearBounds($year);

        $leviable = $this->leviablePayroll($from, $to, $companyId);
        // Read off the start of the levy year, not the end: a levy year running April to March
        // sits almost entirely inside the tax year its April falls in, and taking the end date
        // asks for a tax year that has barely begun.
        $rate = $this->sdlRateFor($from);

        // The exemption is on the annual payroll, so it can only be judged over the whole year —
        // which is exactly why a monthly EMP201 cannot answer this question on its own.
        $exempt = $leviable < self::EXEMPTION_THRESHOLD;
        $levyPaid = $exempt ? 0.0 : round($leviable * $rate, 2);
        $grant = round($levyPaid * self::MANDATORY_GRANT_RATE, 2);
        $claimed = $this->grantReceived($year, $companyId);

        return [
            'year'              => $year,
            'period'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'leviable_payroll'  => round($leviable, 2),
            'levy_paid'         => $levyPaid,
            'exempt'            => $exempt,
            'mandatory_grant'   => $grant,
            'grant_claimed'     => $claimed,
            'grant_outstanding' => round(max(0, $grant - $claimed), 2),
            'submitted'         => $this->wspSubmitted($year, $companyId),
            'deadline'          => Carbon::create($year, 4, 30)->toDateString(),
            'findings'          => $this->findings($exempt, $levyPaid, $grant, $claimed, $year, $companyId),
        ];
    }

    /** April to March, which is the WSP cycle rather than the tax year. */
    public function yearBounds(int $year): array
    {
        return [
            Carbon::create($year - 1, 4, 1)->startOfDay(),
            Carbon::create($year, 3, 31)->endOfDay(),
        ];
    }

    /**
     * Gross pay across both engines, which is what SDL is levied on.
     *
     * The bureau runs pay real people for real employers and their gross is leviable too; leaving
     * it out understates the levy and therefore the grant an employer can claim.
     */
    private function leviablePayroll(Carbon $from, Carbon $to, ?int $companyId): float
    {
        $inHouse = DB::table('payslips')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_end', '<=', $to->toDateString())
            ->sum('gross_pay');

        // The bureau stores gross as `gross`, and the period lives on the run rather than the
        // payslip — so this joins rather than assuming the two tables have the same shape.
        $bureau = 0.0;
        if (DB::getSchemaBuilder()->hasTable('bureau_payslips')) {
            $bureau = DB::table('bureau_payslips as bp')
                ->join('bureau_pay_runs as br', 'br.id', '=', 'bp.bureau_pay_run_id')
                ->when($companyId, fn ($q) => $q->where('bp.company_id', $companyId))
                ->whereDate('br.period_end', '>=', $from->toDateString())
                ->whereDate('br.period_end', '<=', $to->toDateString())
                ->sum('bp.gross');
        }

        return (float) $inHouse + (float) $bureau;
    }

    /** What the LMS recorded as actually received back from the SETA. */
    private function grantReceived(int $year, ?int $companyId): float
    {
        if (! DB::getSchemaBuilder()->hasTable('lms_wsp_submissions')) {
            return 0.0;
        }

        return (float) DB::table('lms_wsp_submissions')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('year', $year)
            ->sum('grant_received');
    }

    private function wspSubmitted(int $year, ?int $companyId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('lms_wsp_submissions')) {
            return false;
        }

        return DB::table('lms_wsp_submissions')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('year', $year)
            ->whereNotNull('submitted_at')
            ->exists();
    }

    /**
     * What somebody should do about it.
     *
     * Each of these is money, and each is invisible without joining the two modules: an employer
     * paying a levy it did not have to, or leaving a fifth of it with the SETA because nobody
     * submitted a plan by the end of April.
     *
     * @return string[]
     */
    private function findings(bool $exempt, float $levy, float $grant, float $claimed, int $year, ?int $companyId): array
    {
        $findings = [];

        if ($exempt && $levy > 0) {
            $findings[] = 'This employer is under the R500 000 threshold and should not be paying SDL at all.';
        }

        if (! $exempt && ! $this->wspSubmitted($year, $companyId)) {
            $deadline = Carbon::create($year, 4, 30);
            $findings[] = $deadline->isPast()
                ? 'No WSP was submitted by 30 April, so ' . number_format($grant, 2) . ' of mandatory grant cannot be claimed for this year.'
                : 'A WSP has to be submitted by 30 April to claim ' . number_format($grant, 2) . ' back.';
        }

        if ($grant > 0 && $claimed > 0 && $claimed < $grant * 0.95) {
            $findings[] = number_format($grant - $claimed, 2) . ' of the mandatory grant is still outstanding from the SETA.';
        }

        return $findings;
    }

    /**
     * The SDL rate in force, falling back to the statutory 1% rather than throwing.
     *
     * A missing tax-year row is a seeding problem, and refusing to report the levy because of one
     * is worse than reporting it at the rate that has not changed since 1999.
     */
    private function sdlRateFor(Carbon $date): float
    {
        try {
            return (float) (SaTaxYear::for($this->taxYearFor($date))->sdl_rate ?? 0.01);
        } catch (\Throwable) {
            return 0.01;
        }
    }

    private function taxYearFor(Carbon $date): int
    {
        return $date->month >= 3 ? $date->year + 1 : $date->year;
    }
}
