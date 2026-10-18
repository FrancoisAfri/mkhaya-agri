<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Enums\PayslipStatus;
use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use App\Modules\HR\Payroll\Models\PayrollEarningsLine;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\HR\Payroll\Support\LabourRates;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use Illuminate\Support\Carbon;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollService
{
    private const UIF_CAP              = 177.12;
    private const PAYE_ANNUAL_THRESHOLD = 95750.00;
    private const PAYE_RATE            = 0.18;
    private const DEFAULT_UIF_RATE     = 0.01;

    // ── Run management ───────────────────────────────────────────────────────

    public function createPayrollRun(array $data, int $userId): PayrollRun
    {
        $periodStart = $data['period_start'] ?? now()->startOfMonth();
        $sequence    = PayrollRun::whereYear('period_start', date('Y', strtotime((string) $periodStart)))
            ->whereMonth('period_start', date('m', strtotime((string) $periodStart)))
            ->count() + 1;

        $refNumber = 'PR-' . date('Ym', strtotime((string) $periodStart))
            . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        // A run belongs to one cycle, decided when it is created and not after: everything the run
        // then does — who may go on it, how their salary is scaled, how many periods PAYE is
        // annualised over — reads from this. Where the caller does not say, it is the company
        // default, which is what every run was before runs carried a frequency at all.
        $frequency = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::canonicalFrequency($data['frequency'] ?? null)
            ?? $this->payFrequency();

        $run = PayrollRun::create(array_merge($data, [
            'created_by'       => $userId,
            'ref_number'       => $refNumber,
            'frequency'        => $frequency,
            'status'           => PayrollRunStatus::Draft->value,
            'total_gross'      => 0,
            'total_deductions' => 0,
            'total_net'        => 0,
            'employee_count'   => 0,
        ]));

        $this->audit($run, $userId, 'run.created', "Payroll run {$refNumber} created.");

        return $run;
    }

    public function lockPayrollRun(PayrollRun $run, int $userId): void
    {
        $lockedAt = now()->toDateTimeString();
        $run->update([
            'status' => PayrollRunStatus::Review->value,
            'notes'  => trim(($run->notes ?? '') . "\nLocked at: {$lockedAt} by user {$userId}"),
        ]);

        $this->audit($run, $userId, 'run.locked', "Run locked to Review status.");
    }

    public function releasePayrollRun(PayrollRun $run, int $userId): void
    {
        $run->update([
            'status'      => PayrollRunStatus::Released->value,
            'released_at' => now(),
            'released_by' => $userId,
        ]);

        // Finalise each payslip so its status reflects the released run (was staying 'draft').
        $run->payslips()->update(['status' => \App\Modules\HR\Payroll\Enums\PayslipStatus::Finalised->value]);

        // Lock + store each payslip as an encrypted PDF under the employee profile.
        $this->lockAndStorePayslips($run);

        // Email the locked payslip + send an in-app notification to each employee.
        $this->emailAndNotifyPayslips($run);

        /*
         * Work out what each fund is owed for this run.
         *
         * The deductions have left the employees' pay; this is the record of who they are owed to
         * and by when. Best-effort like the ledger posting below — a fund's schedule failing to
         * build must never hold up a release that has already paid people.
         */
        try {
            app(RemittanceService::class)->buildForRun($run);
        } catch (\Throwable $e) {
            Log::warning('Remittances could not be built for run ' . $run->id . ': ' . $e->getMessage());
        }

        $this->audit($run, $userId, 'run.released', "Run released by user {$userId}.", [
            'total_net'      => (float) $run->total_net,
            'employee_count' => (int) $run->employee_count,
        ]);

        // Post the salary journal to the General Ledger via the Finance outbox.
        // Best-effort: any failure is recorded in fin_postings for retry and must
        // never block the payroll release itself.
        try {
            app(\App\Modules\HR\Payroll\Services\PayrollLedgerPoster::class)->postRun($run->fresh());
        } catch (\Throwable $e) {
            Log::warning('payroll: GL posting hook failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }

        // Tell the budget module what this run cost, split by department. Payroll is usually the
        // largest operating line a company has, and until this existed the budget only ever heard
        // about purchase orders — so every departmental budget looked healthier than it was.
        // Best-effort for the same reason as the GL posting above: a released run must never be
        // unwound because a budget was misconfigured. The bridge is idempotent, so a re-release
        // cannot charge the budget twice.
        try {
            app(\App\Modules\HR\Payroll\Services\PayrollBudgetPoster::class)->postRun($run->fresh());
        } catch (\Throwable $e) {
            Log::warning('payroll: budget posting hook failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * On release, email each employee their password-protected payslip and send
     * an in-app notification. Best-effort: a failure never blocks the release.
     */
    public function emailAndNotifyPayslips(PayrollRun $run): void
    {
        $companyName = (string) (DB::table('company_identities')->value('company_name') ?: 'Payroll');
        $period = $run->period_start && $run->period_end
            ? \Illuminate\Support\Carbon::parse($run->period_start)->format('M Y')
            : (string) $run->name;

        foreach ($run->payslips()->get() as $payslip) {
            try {
                if (! $payslip->hasBytes()) {
                    continue;
                }
                $user = \App\Core\User\Models\User::find($payslip->employee_id);
                if (! $user) {
                    continue;
                }
                $name = $this->resolveEmployeeName($payslip->employee_id);

                if (! blank($user->email)) {
                    \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Modules\HR\Payroll\Mail\BureauPayslipMail(
                        $companyName, $name, $period, (string) $payslip->bytes(),
                    ));
                }
                $user->notify(new \App\Modules\HR\Payroll\Notifications\PayslipReadyNotification($payslip, $period));
            } catch (\Throwable $e) {
                Log::warning('Payslip email/notify failed', ['payslip' => $payslip->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Render each released payslip to a password-protected PDF (locked with the
     * employee's payslip key) and store it on the payslip, under their profile.
     * Best-effort: a rendering failure for one employee never blocks the release.
     */
    public function lockAndStorePayslips(PayrollRun $run): void
    {
        $secure = app(\App\Modules\HR\Payroll\Services\SecurePayslipService::class);

        foreach ($run->payslips()->get() as $payslip) {
            try {
                $person = \App\Modules\HR\Hr\Models\HRPerson::where('user_id', $payslip->employee_id)->first();
                if (! $person) {
                    continue;
                }
                $key = $person->ensurePayslipKey();

                $payslip->load('earningsLines');
                $payslip->employee_name = $this->resolveEmployeeName($payslip->employee_id);
                $html = view('pdf.payslip', $this->payslipViewData($payslip))->render();

                // Reloaded clean so the view-only employee_name attribute is not persisted —
                // the reason this was a targeted update rather than a save.
                $stored = Payslip::findOrFail($payslip->id);
                $stored->writeBytes($secure->renderProtected($html, $key), 'pdf');
                $stored->is_locked = true;
                $stored->save();
            } catch (\Throwable $e) {
                Log::warning('Secure payslip storage failed', ['payslip' => $payslip->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function resolveEmployeeName(int $id): string
    {
        $row = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $id)
            ->selectRaw("COALESCE(TRIM(CONCAT(p.first_name,' ',COALESCE(p.surname,''))), u.email) AS name")
            ->first();

        return $row?->name ?? "User #{$id}";
    }

    // ── Employee management ──────────────────────────────────────────────────

    public function addEmployeeToRun(PayrollRun $run, int $employeeId, array $salaryData): Payslip
    {
        return Payslip::create(array_merge([
            'payroll_run_id'   => $run->id,
            'employee_id'      => $employeeId,
            'period_start'     => $run->period_start,
            'period_end'       => $run->period_end,
            'status'           => PayslipStatus::Draft->value,
            'basic_salary'     => 0,
            'gross_pay'        => 0,
            'total_deductions' => 0,
            'net_pay'          => 0,
            'paye'             => 0,
            'uif'              => 0,
            'pension'          => 0,
        ], $salaryData));
    }

    /**
     * Put people on a run.
     *
     * @return array{added: int, mismatched: list<string>}  who went on, and who was held back
     *                                                       because they are not paid on this cycle
     */
    public function generatePayrollItems(PayrollRun $run, array $employeeIds): array
    {
        $added = 0;
        $mismatched = [];

        // A run pays one cycle. Where the run does not say which, it is the company default —
        // an older run created before runs carried a frequency at all.
        $runFrequency = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::canonicalFrequency($run->frequency)
            ?? $this->payFrequency();

        foreach ($employeeIds as $employeeId) {
            // Skip if already on this run
            if (Payslip::where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->exists()) {
                continue;
            }

            try {
                $person      = DB::table('hr_people')->where('user_id', $employeeId)->first();

                /*
                 * A weekly employee does not belong on the monthly run.
                 *
                 * Not a technicality: the run's period is a month, so they would be paid a month's
                 * wages, and their own weekly run for those same weeks would pay them again. The
                 * payslip overlap constraint would refuse the second one — but by then the wrong
                 * payment is the one already made, and the run that looks broken is the right one.
                 */
                $theirs = $this->frequencyFor($person);
                if ($theirs !== $runFrequency) {
                    $mismatched[] = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) ?: "#{$employeeId}";
                    continue;
                }

                // HR captures pay in monthly_package (the profile Package tab), so it is scaled
                // to the length of this employee's pay period.
                $basicSalary = $person ? $this->periodBasicSalary((float) ($person->monthly_package ?? 0), $theirs) : 0.0;

                /*
                 * Then reduced to the part of the period they were actually employed for.
                 *
                 * This used to pay the full period regardless of when somebody started or left, so
                 * a joiner on the 20th was paid the whole month. Not a missing feature — money
                 * going out of the door.
                 */
                $proRata = $person
                    ? app(ProRataCalculator::class)->forPerson($person, $run->period_start, $run->period_end)
                    : ['fraction' => 1.0, 'partial' => false, 'days_paid' => 0, 'days_in_period' => 0, 'reason' => null, 'basis' => 'working'];

                $this->addEmployeeToRun($run, $employeeId, [
                    'basic_salary' => app(ProRataCalculator::class)->apply($basicSalary, $proRata),
                    'pro_rata'     => $proRata['partial'] ? $proRata : null,
                ]);
                $added++;
            } catch (\Throwable $e) {
                Log::warning("PayrollService: failed to add employee {$employeeId} to run {$run->id}: " . $e->getMessage());
            }
        }

        $this->updateRunTotals($run);
        $this->audit($run, auth()->id() ?? 0, 'run.employees_added', "{$added} employee(s) added to run.", [
            'added'      => $added,
            'frequency'  => $runFrequency,
            'mismatched' => $mismatched,
        ]);

        return ['added' => $added, 'mismatched' => $mismatched];
    }

    // ── Calculations ─────────────────────────────────────────────────────────

    public function calculateGrossPay(Payslip $_payslip, array $lines): float
    {
        $gross = 0.0;
        foreach ($lines as $line) {
            $type   = $line instanceof PayrollEarningsLine ? $line->line_type : null;
            if ($type && $type->isDeduction()) {
                continue;
            }
            $amount = $line instanceof PayrollEarningsLine ? (float) $line->amount : (float) ($line['amount'] ?? 0);
            $gross += $amount;
        }
        return $gross;
    }

    /**
     * How old an employee was at the end of a pay period, or null if we cannot tell.
     *
     * `hr_people.date_of_birth` is a Unix timestamp in a bigint column — a survival from the legacy
     * intranet — and a raw query returns it as a *string*, which Carbon::parse rejects outright
     * ("Failed to parse time string (1073001600)"). Both the PAYE path and the incentive read it
     * that way, so the first employer to capture a date of birth would have had the whole run throw
     * rather than mis-price a rebate. Nobody hit it only because the column is empty everywhere.
     *
     * Both shapes are accepted, because the column is also written as a date string in places.
     */
    private function ageAt(?object $person, mixed $periodEnd): ?int
    {
        $dob = $person->date_of_birth ?? null;

        if (blank($dob)) {
            return null;
        }

        $born = is_numeric($dob) ? Carbon::createFromTimestamp((int) $dob) : Carbon::parse($dob);

        return (int) $born->diffInYears(Carbon::parse($periodEnd));
    }

    /**
     * The Employment Tax Incentive this payslip earns the employer.
     *
     * Not a deduction, and deliberately not part of calculateDeductions(): the incentive belongs to
     * the employer and reduces what they owe SARS on the EMP201. An employee whose net pay rose
     * because their employer qualified would be a serious error, not a bonus.
     *
     * Counted from the claim ledger rather than from tenure, and recorded there whether or not
     * anything was earned — including the reason, because "why did this person claim nothing in
     * June" has to be answerable long after the run that decided it.
     */
    public function calculateEti(Payslip $payslip, float $grossPay): float
    {
        $settings = PayrollSetting::first();
        $engine = app(\App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::class);
        $claims = app(EtiClaimService::class);

        $taxYear = $this->resolveTaxYear($payslip->period_end);

        $person = DB::table('hr_people')->where('user_id', $payslip->employee_id)->first();
        if (! $person) {
            return 0.0;
        }

        $frequency = $this->frequencyFor($person);

        $age = $this->ageAt($person, $payslip->period_end);

        // The bands are monthly, so a weekly or fortnightly wage is restated before it is measured
        // against them — otherwise a weekly R2 000 (R8 667 a month, well over the ceiling) would
        // claim the maximum. The incentive is then scaled back to this pay period.
        $monthlyRemuneration = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::toMonthly($grossPay, $frequency);
        $periodKey = $claims->periodKeyFor($payslip->period_end);
        $claimMonth = $claims->nextClaimMonth(EtiClaim::HR, (int) $person->id, $periodKey);

        $eti = 0.0;
        $reason = null;

        if (! ($person->eti_opt_in ?? false)) {
            $reason = 'Not opted in to the incentive';
        } elseif ($claimMonth > EtiClaim::MAX_MONTHS) {
            $reason = 'All ' . EtiClaim::MAX_MONTHS . ' qualifying months have been claimed';
        } else {
            $monthly = $engine->eti($monthlyRemuneration, $claimMonth, $age, $taxYear);
            $eti = round($monthly * 12 / \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::periodsFor($frequency), 2);

            if ($eti <= 0.0) {
                $reason = match (true) {
                    $age === null          => 'No date of birth, so age could not be established',
                    $age < 18 || $age > 29 => 'Age ' . $age . ' is outside the qualifying range',
                    default                => 'Remuneration of ' . round($monthlyRemuneration, 2) . ' a month falls outside the qualifying bands',
                };
            }
        }

        $claims->record(
            EtiClaim::HR, (int) $person->id, $periodKey,
            $payslip->period_start, $payslip->period_end, $taxYear,
            $eti, $monthlyRemuneration, $age, $claimMonth, $reason,
            ['payroll_run_id' => $payslip->payroll_run_id],
        );

        return $eti;
    }

    public function calculateDeductions(Payslip $payslip, float $grossPay): array
    {
        $settings    = PayrollSetting::first();
        $employeeId  = $payslip->employee_id;
        $engine      = app(\App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::class);

        $taxYear     = $this->resolveTaxYear($payslip->period_end);

        // Employee statutory inputs from their HR profile (age, medical scheme).
        $person = DB::table('hr_people')->where('user_id', $employeeId)->first();

        // Theirs, not the company's — PAYE is annualised on it, so a weekly employee read as
        // monthly is taxed as though they earned fifty-two weeks' pay every week.
        $frequency   = $this->frequencyFor($person);
        $age = $this->ageAt($person, $payslip->period_end);
        $medMembers    = ($person && ((float) ($person->med_amount ?? 0) > 0 || ! empty($person->med_plan_name))) ? 1 : 0;
        $medDependants = $person ? (int) (($person->med_dep_spouse ?? 0) + ($person->med_dep_adult ?? 0) + ($person->med_dep_kids ?? 0)) : 0;

        // Pension/retirement (pre-tax) from earnings lines.
        $pension = (float) DB::table('payroll_earnings_lines')
            ->where('payslip_id', $payslip->id)
            ->where('line_type', EarningsLineType::Pension->value)
            ->sum('amount');

        // Travel allowance: only 80% is subject to PAYE (s8(1)), so 20% is non-taxable.
        $travelAllowance = (float) DB::table('payroll_earnings_lines')
            ->where('payslip_id', $payslip->id)
            ->where('line_type', EarningsLineType::TravelAllowance->value)
            ->sum('amount');
        $travelNonTaxable = round($travelAllowance * (1 - \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::TRAVEL_TAXABLE_PORTION), 2);

        // Employer contributions (medical/pension) are TAXABLE FRINGE BENEFITS: they raise
        // the PAYE base but are not cash — they don't touch gross or net pay.
        $employerFringe = (float) DB::table('payroll_earnings_lines')
            ->where('payslip_id', $payslip->id)
            ->whereIn('line_type', [EarningsLineType::EmployerMedical->value, EarningsLineType::EmployerPension->value])
            ->sum('amount');

        // Retirement is deductible only up to the s11F cap (27.5% / annual cap); any excess
        // is still paid by the employee but does NOT reduce taxable income.
        $taxableBeforeRetirement = max(0.0, $grossPay + $employerFringe - $travelNonTaxable);
        $deductibleRetirement = $engine->deductibleRetirement($pension, $grossPay, $taxableBeforeRetirement, $frequency, $taxYear);

        // PAYE via the SARS engine (brackets + age rebates + medical credits), on income
        // net of the non-taxable travel portion and the s11F-capped retirement deduction,
        // plus any taxable employer-contribution fringe benefits.
        $taxableIncome = max(0.0, $grossPay + $employerFringe - $travelNonTaxable - $deductibleRetirement);
        $paye = $settings?->paye_enabled !== false
            ? $engine->periodPaye($taxableIncome, $frequency, $age, $taxYear, $medMembers, $medDependants)
            : 0.0;

        // UIF via the SARS ceiling (1% employee, capped to the length of this pay period).
        $uif = $settings?->uif_enabled !== false
            ? $engine->uif($grossPay, $taxYear, 'ZA', $frequency)['employee']
            : 0.0;

        // Active staff loans. The instalment is agreed as a monthly figure, so on a weekly or
        // fortnightly payroll only the slice that falls in this period may be recovered —
        // otherwise a monthly instalment is taken 52 times a year instead of 12.
        $loanDeduction = round((float) DB::table('staff_loans')
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->sum('monthly_installment') * 12 / \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::periodsFor($frequency), 2);

        // Salary advance repayment falling in THIS payslip's period. Keying this off today's date
        // meant a run processed late (or a re-run of a closed month) recovered the wrong month's
        // advances — or none at all.
        $yearMonth         = \Illuminate\Support\Carbon::parse($payslip->period_end)->format('Y-m');
        $advanceDeduction  = (float) DB::table('salary_advances')
            ->where('employee_id', $employeeId)
            ->where('status', 'disbursed')
            ->whereRaw("TO_CHAR(repayment_month, 'YYYY-MM') = ?", [$yearMonth])
            ->sum('amount_approved');

        // Manual deduction lines entered on the payslip (medical aid, union, garnishee, etc.).
        $otherDeductions = (float) DB::table('payroll_earnings_lines')
            ->where('payslip_id', $payslip->id)
            ->whereIn('line_type', [EarningsLineType::MedicalAid->value, EarningsLineType::UnpaidLeave->value, EarningsLineType::OtherDeduction->value])
            ->sum('amount');

        $total = $paye + $uif + $pension + $loanDeduction + $advanceDeduction + $otherDeductions;

        /*
         * What happens when the deductions do not all fit.
         *
         * Summing them and subtracting is only right while there is enough to go round. A month
         * with a garnishee, a maintenance order and a loan can take somebody to zero, and which
         * creditor loses out should be decided by law rather than by row order. The resolver ranks
         * them, trims the lowest-priority ones to leave the employee a floor, and reports what
         * fell short so a creditor can be told.
         */
        $priority = app(DeductionPriorityService::class)->resolve($payslip, $grossPay);

        if ($priority['shortfall'] > 0) {
            // The competing deductions are capped; PAYE, UIF and retirement are not part of that
            // contest and keep their own figures.
            $otherDeductions = max(0.0, $priority['total'] - $paye - $uif - $pension);
            $total = $paye + $uif + $pension + $loanDeduction + $advanceDeduction + $otherDeductions;

            Log::warning('payroll: deductions exceeded available pay, lower-priority items trimmed', [
                'payslip'   => $payslip->id,
                'shortfall' => $priority['shortfall'],
                'trimmed'   => array_column($priority['trimmed'], 'description'),
            ]);
        }

        $deductionPriority = $priority;

        return compact('paye', 'uif', 'pension', 'loanDeduction', 'advanceDeduction',
            'otherDeductions', 'total', 'deductionPriority');
    }

    /**
     * The configured pay frequency, in the vocabulary the tax engine understands.
     *
     * An unrecognised value still falls back to monthly — a payroll run must not be blocked by a
     * settings row — but it says so in the log rather than passing silently, because the symptom
     * (PAYE annualised at the wrong number of periods) is otherwise invisible on the payslip.
     */
    public function payFrequency(?PayrollSetting $settings = null): string
    {
        $stored = ($settings ?? PayrollSetting::first())?->pay_frequency;
        $frequency = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::canonicalFrequency($stored);

        if ($frequency === null && ! blank($stored)) {
            Log::warning('payroll: unknown pay frequency, defaulting to monthly', ['pay_frequency' => $stored]);
        }

        return $frequency ?? 'monthly';
    }

    /**
     * The frequency to pay one person on — theirs, not the company's.
     *
     * The company setting is a default, not a fact about an individual. A firm with monthly staff
     * and weekly wage earners has both at once, and reading the setting for everybody paid the
     * weekly people a full month's salary and then annualised their PAYE over twelve periods
     * instead of fifty-two.
     *
     * Only two things are consulted, and deliberately not the run's own frequency. A run and the
     * people on it already agree — that is what generatePayrollItems() enforces — so the run could
     * only ever break the tie for a payslip whose employee has no HR record at all, and there the
     * company setting is the better answer. Consulting it did real harm: a run carries the column's
     * default until somebody chooses otherwise, and a default that outranks a deliberate company
     * setting is a silent wrong answer.
     *
     * @param  object|null  $person  an hr_people row (Eloquent model or stdClass from the query builder)
     */
    public function frequencyFor(?object $person): string
    {
        $stored = $person->pay_frequency ?? null;

        if (blank($stored)) {
            return $this->payFrequency();
        }

        if ($canonical = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::canonicalFrequency($stored)) {
            return $canonical;
        }

        // Never silently monthly: an unreadable value on the person is the case that has to be
        // seen, because the payslip it produces looks entirely normal.
        Log::warning('payroll: unknown pay frequency on employee, falling back', [
            'pay_frequency' => $stored,
            'employee_id'   => $person->user_id ?? null,
        ]);

        return $this->payFrequency();
    }

    /**
     * An employee's HR package restated as one pay period's basic salary.
     *
     * HR captures a monthly package, so paying weekly without this puts a full month's salary on
     * every weekly payslip — and then taxes it as if it recurred 52 times a year.
     */
    public function periodBasicSalary(float $monthlyPackage, ?string $frequency = null): float
    {
        $periods = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::periodsFor($frequency ?? $this->payFrequency());

        return round($monthlyPackage * 12 / $periods, 2);
    }

    /**
     * SARS year of assessment for a period (Mar–Feb). Falls back to the latest
     * seeded tax year when the computed one isn't loaded.
     */
    private function resolveTaxYear($periodEnd): int
    {
        $date = \Illuminate\Support\Carbon::parse($periodEnd);
        $yoa  = $date->month >= 3 ? $date->year + 1 : $date->year;

        $exists = \App\Modules\HR\Payroll\Models\Bureau\SaTaxYear::where('tax_year', $yoa)->exists();

        return $exists ? $yoa : (int) \App\Modules\HR\Payroll\Models\Bureau\SaTaxYear::max('tax_year');
    }

    public function calculateNetPay(float $grossPay, float $totalDeductions): float
    {
        return round($grossPay - $totalDeductions, 2);
    }

    /**
     * Calculate gross → deductions → net for every payslip in the run
     * using basic_salary as the single earnings line.
     */
    public function calculateAllPayslips(PayrollRun $run, int $userId): int
    {
        /*
         * The bargaining council levy, before anything is totalled.
         *
         * It produces deduction lines like any other, so it has to exist before gross and
         * deductions are computed — a levy calculated after the fact is a levy nobody pays.
         */
        try {
            app(BargainingCouncilService::class)->applyToRun($run);
        } catch (\Throwable $e) {
            Log::warning('Bargaining council levy could not be applied to run ' . $run->id . ': ' . $e->getMessage());
        }

        $count = 0;
        foreach ($run->payslips as $payslip) {
            // Gross = basic salary + any earnings lines added to the payslip (allowances,
            // travel, overtime, bonus, commission). Basic may be stored as the column OR,
            // on legacy line-based payslips, as a BasicSalary line — take the larger.
            $basicLine = (float) DB::table('payroll_earnings_lines')
                ->where('payslip_id', $payslip->id)
                ->where('line_type', EarningsLineType::BasicSalary->value)
                ->sum('amount');
            $basic = max((float) $payslip->basic_salary, $basicLine);
            $earningsAdditions = (float) DB::table('payroll_earnings_lines')
                ->where('payslip_id', $payslip->id)
                ->whereIn('line_type', [
                    EarningsLineType::Overtime->value,
                    EarningsLineType::Bonus->value,
                    EarningsLineType::Allowance->value,
                    EarningsLineType::TravelAllowance->value,
                    EarningsLineType::Commission->value,
                    EarningsLineType::LeavePayout->value,
                ])->sum('amount');
            $grossPay          = $basic + $earningsAdditions;
            $deductions        = $this->calculateDeductions($payslip, $grossPay);
            $netPay            = $this->calculateNetPay($grossPay, $deductions['total']);

            $payslip->update([
                'gross_pay'        => $grossPay,
                'paye'             => $deductions['paye'],
                'uif'              => $deductions['uif'],
                'pension'          => $deductions['pension'],
                'total_deductions' => $deductions['total'],
                // The employer's incentive, kept out of the employee's deductions and out of net.
                'eti'              => $this->calculateEti($payslip, $grossPay),
                'net_pay'          => $netPay,
            ]);
            $count++;
        }

        $this->updateRunTotals($run);
        $this->audit($run, $userId, 'run.calculated', "Payslips calculated for {$count} employee(s).", [
            'employee_count' => $count,
            'total_net'      => (float) $run->fresh()->total_net,
        ]);

        return $count;
    }

    /**
     * Re-sync each draft payslip's basic salary from the employee's current HR
     * profile (monthly_package). Use after someone's package changes so the run
     * reflects the new figure; the caller then recalculates PAYE/UIF/net.
     */
    public function refreshBasicSalaries(PayrollRun $run): int
    {
        $updated = 0;

        foreach ($run->payslips as $payslip) {
            $person = DB::table('hr_people')->where('user_id', $payslip->employee_id)->first();
            $salary = $person
                ? $this->periodBasicSalary((float) ($person->monthly_package ?? 0), $this->frequencyFor($person))
                : (float) $payslip->basic_salary;
            if ((float) $payslip->basic_salary !== $salary) {
                $payslip->update(['basic_salary' => $salary]);
                $updated++;
            }
        }

        return $updated;
    }

    public function updateRunTotals(PayrollRun $run): void
    {
        $totals = DB::table('payslips')
            ->where('payroll_run_id', $run->id)
            ->whereNull('deleted_at')   // raw query bypasses the SoftDeletes scope — exclude trashed
            ->selectRaw('
                COUNT(*) as employee_count,
                COALESCE(SUM(gross_pay), 0) as total_gross,
                COALESCE(SUM(total_deductions), 0) as total_deductions,
                COALESCE(SUM(eti), 0) as total_eti,
                COALESCE(SUM(net_pay), 0) as total_net
            ')
            ->first();

        $run->update([
            'employee_count'   => $totals->employee_count ?? 0,
            'total_gross'      => $totals->total_gross ?? 0,
            'total_deductions' => $totals->total_deductions ?? 0,
            // What the employer may set against their PAYE liability on the EMP201.
            'total_eti'        => $totals->total_eti ?? 0,
            'total_net'        => $totals->total_net ?? 0,
        ]);
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    public function audit(PayrollRun $run, int $userId, string $action, string $description, array $meta = []): void
    {
        try {
            $request = app()->runningInConsole() ? null : request();

            PayrollAuditLog::create([
                'run_id'      => $run->id,
                'user_id'     => $userId,
                'action'      => $action,
                'description' => $description,
                'meta'        => $meta ?: null,
                'ip_address'  => $request?->ip(),
                'user_agent'  => $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("PayrollService audit log failed: " . $e->getMessage());
        }
    }

    /**
     * Everything the payslip document has to state, gathered once.
     *
     * The view used to be handed the payslip alone, so it had no employer to name and carried the
     * literal string "MkhayaHub" — every payslip named the software vendor. BCEA §33 wants the
     * employer's name and address, the employee's name and occupation, and the period; none of it
     * was reachable from a payslip on its own.
     */
    public function payslipViewData(Payslip $payslip): array
    {
        $settings = PayrollSetting::first();

        // `position` and `position_id` are integer keys into hr_positions, not the job title —
        // reading either as text puts a number where BCEA §33(1)(b) wants an occupation.
        $person = DB::table('hr_people as p')
            ->leftJoin('hr_positions as pos', function ($join) {
                $join->on('pos.id', '=', 'p.position_id')->orOn('pos.id', '=', 'p.position');
            })
            ->where('p.user_id', $payslip->employee_id)
            ->select(
                'p.first_name', 'p.surname', 'p.employee_number', 'p.id_number',
                'p.income_tax_number', 'p.date_joined', 'pos.name as occupation',
            )
            ->first();

        // Resolved at read time rather than trusted to a one-off backfill: a settings row whose
        // company has since been removed would otherwise fall all the way through to the app name,
        // which is the bug this whole change exists to fix.
        $tenantName = DB::table('companies')
            ->where('id', $settings?->company_id)
            ->value('name');

        return [
            // Why this payslip is for less than a full period. An employee paid less than they
            // expected needs to be told why on the document itself, not asked to work it out.
            'pro_rata' => $payslip->pro_rata,
            'payslip'  => $payslip,
            'employer' => [
                'name'         => $settings?->employer_name ?: $tenantName ?: config('app.name'),
                'trading_name' => $settings?->employer_trading_name,
                'address'      => $settings?->employer_address,
                'paye_ref'     => $settings?->paye_reference,
                'uif_ref'      => $settings?->uif_reference,
                'contact'      => $settings?->employer_contact,
            ],
            'employee' => [
                'name'       => trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) ?: $payslip->employee_name,
                'number'     => $person->employee_number ?? null,
                'id_number'  => $person->id_number ?? null,
                'tax_number' => $person->income_tax_number ?? null,
                // BCEA §33(1)(b) asks for the occupation, which is not the same as the job grade.
                'occupation' => $person->job_title ?? null,
            ],
            'ytd'      => $this->yearToDate($payslip),
            'hours'    => $this->hoursFor($payslip, $settings),
            'leave'    => $this->leaveBalancesFor($payslip),
            'pay_date' => $payslip->period_end,
        ];
    }

    /**
     * Year-to-date totals, which every South African payslip carries and this one did not.
     *
     * Counted across the tax year the period ends in — March to February — rather than the calendar
     * year, because that is the year an IRP5 reconciles against.
     */
    public function yearToDate(Payslip $payslip): array
    {
        $end = Carbon::parse($payslip->period_end);
        $yearStart = $end->copy()->month >= 3
            ? Carbon::create($end->year, 3, 1)
            : Carbon::create($end->year - 1, 3, 1);

        $row = DB::table('payslips')
            ->where('employee_id', $payslip->employee_id)
            ->whereDate('period_end', '>=', $yearStart)
            ->whereDate('period_end', '<=', $end)
            ->selectRaw('COALESCE(SUM(gross_pay),0) gross, COALESCE(SUM(paye),0) paye,
                         COALESCE(SUM(uif),0) uif, COALESCE(SUM(pension),0) pension,
                         COALESCE(SUM(net_pay),0) net')
            ->first();

        return [
            'gross'   => (float) ($row->gross ?? 0),
            'paye'    => (float) ($row->paye ?? 0),
            'uif'     => (float) ($row->uif ?? 0),
            'pension' => (float) ($row->pension ?? 0),
            'net'     => (float) ($row->net ?? 0),
            'since'   => $yearStart->toDateString(),
        ];
    }

    /**
     * Hours worked in the period, split the way BCEA §33(1)(g) asks for them.
     *
     * Read from attendance, which already records hours and overtime per day. The Sunday and
     * public-holiday split is derived from the date rather than captured separately — nobody should
     * have to record twice what a calendar already knows.
     *
     * Returns an empty array when nothing was captured, so the payslip omits the block entirely
     * rather than printing zeros at a salaried employee.
     */
    public function hoursFor(Payslip $payslip, ?PayrollSetting $settings = null): array
    {
        $person = DB::table('hr_people')->where('user_id', $payslip->employee_id)->value('id');

        if (! $person) {
            return [];
        }

        $rows = DB::table('attendance_records')
            // `employee_id` on this table is an hr_people id, not a user id — the cross-check
            // service documents the same trap.
            ->where('employee_id', $person)
            ->whereNull('deleted_at')
            ->whereDate('date', '>=', $payslip->period_start)
            ->whereDate('date', '<=', $payslip->period_end)
            ->get(['date', 'hours_worked', 'overtime_hours']);

        if ($rows->isEmpty()) {
            return [];
        }

        $holidays = $this->publicHolidaysBetween($payslip->period_start, $payslip->period_end);

        $ordinary = $overtime = $sunday = $publicHoliday = 0.0;

        foreach ($rows as $row) {
            $day = Carbon::parse($row->date);
            $worked = (float) ($row->hours_worked ?? 0);
            $ot = (float) ($row->overtime_hours ?? 0);

            $overtime += $ot;

            // A day is counted once, in the most expensive category it qualifies for: a public
            // holiday that falls on a Sunday is paid as one premium, not two.
            if (in_array($day->toDateString(), $holidays, true)) {
                $publicHoliday += $worked;
            } elseif ($day->isSunday()) {
                $sunday += $worked;
            } else {
                $ordinary += $worked;
            }
        }

        $rate = $this->hourlyRateFor($payslip, $settings);

        return [
            'rate'           => $rate,
            'overtime_rate'  => round($rate * (float) app(LabourRates::class)->overtimeMultiplier($payslip->period_end), 2),
            'ordinary'       => round($ordinary, 2),
            'overtime'       => round($overtime, 2),
            'sunday'         => round($sunday, 2),
            'public_holiday' => round($publicHoliday, 2),
        ];
    }

    /** The ordinary hourly rate, from the monthly package over the standard month. */
    public function hourlyRateFor(Payslip $payslip, ?PayrollSetting $settings = null): float
    {
        $monthlyHours = app(LabourRates::class)->standardMonthlyHours($payslip->period_end);

        return $monthlyHours > 0
            ? round(((float) $payslip->basic_salary) / $monthlyHours, 2)
            : 0.0;
    }

    /** @return list<string> the public holidays inside the period, as Y-m-d */
    /** Public so the pro-rata calculator can count working days the same way the run does. */
    public function publicHolidaysBetween($from, $to): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('public_holidays')) {
            return [];
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        return DB::table('public_holidays')
            ->get(['day', 'year'])
            ->map(function ($row) use ($start) {
                // The column mixes Unix timestamps with recurring MM-DD entries, so both shapes are
                // resolved into the year being paid rather than assumed to be one or the other.
                $value = trim((string) $row->day);

                if ($value === '') {
                    return null;
                }

                if (is_numeric($value) && strlen($value) >= 9) {
                    return Carbon::createFromTimestamp((int) $value)->toDateString();
                }

                if (preg_match('/^\d{2}-\d{2}$/', $value)) {
                    return ($row->year ?: $start->year) . '-' . $value;
                }

                return rescue(fn () => Carbon::parse($value)->toDateString(), null, false);
            })
            ->filter()
            ->filter(fn ($date) => $date >= $start->toDateString() && $date <= $end->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Leave balances to print on the payslip.
     *
     * Read through LeaveBalanceService, which is the only thing that knows how a balance is derived
     * — it is a sum over the ledger, not a stored figure, and duplicating that arithmetic here would
     * give a payslip that disagrees with the leave screen.
     *
     * Empty where leave is not administered for this person, so the payslip omits the block rather
     * than printing a box of zeros.
     *
     * @return list<array{type:string, available:float, taken:float}>
     */
    public function leaveBalancesFor(Payslip $payslip): array
    {
        $person = DB::table('hr_people')->where('user_id', $payslip->employee_id)->value('id');

        if (! $person) {
            return [];
        }

        try {
            $balances = app(\App\Modules\HR\Leave\Services\LeaveBalanceService::class)
                ->getAllBalances((int) $person);
        } catch (\Throwable $e) {
            // Leave is a separate module and may not be installed. A payslip must still print.
            Log::warning('payslip: leave balances unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($balances)
            // Only the types this person actually holds. A company with forty configured leave
            // types would otherwise push the payslip onto a second page of zeros.
            ->filter(fn ($b) => (float) ($b->balance_days ?? 0) != 0.0)
            ->map(fn ($b) => [
                'type'      => $b->leave_type_name ?? 'Leave',
                'available' => round((float) ($b->balance_days ?? 0), 2),
                'taken'     => round((float) ($b->taken_days ?? 0), 2),
            ])
            ->values()
            ->all();
    }
}
