<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Models\PayrollEarningsLine;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pulls leave data from the Leave module into a payroll run when that module is
 * active for the tenant: unpaid leave becomes a deduction (pro-rated on the basic
 * salary), and leave pay-outs become an earning. Idempotent — re-syncing replaces
 * the auto-created leave lines rather than stacking them.
 */
class LeavePayrollService
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** True only when the Leave module is installed AND the tenant's plan/licence includes it. */
    public function leaveAvailable(): bool
    {
        $installed = DB::table('security_modules')
            ->where('code_name', 'leave_management')->where('active', 1)->exists();

        return $installed && app(CompanyEntitlements::class)->currentAllowsModule('leave_management');
    }

    /** @return array{available:bool, synced:int} */
    public function syncLeaveForRun(PayrollRun $run): array
    {
        if (! $this->leaveAvailable()) {
            return ['available' => false, 'synced' => 0];
        }

        $start = Carbon::parse($run->period_start);
        $end   = Carbon::parse($run->period_end);
        $weekdays = $this->weekdaysBetween($start, $end);
        $synced = 0;

        foreach ($run->payslips as $payslip) {
            $hrId = DB::table('hr_people')->where('user_id', $payslip->employee_id)->value('id');
            if (! $hrId) {
                continue;
            }

            // Idempotent: clear previously auto-synced leave lines for this payslip.
            DB::table('payroll_earnings_lines')->where('payslip_id', $payslip->id)
                ->whereIn('line_type', [EarningsLineType::UnpaidLeave->value, EarningsLineType::LeavePayout->value])
                ->delete();

            // ── Unpaid leave → a deduction pro-rated on the basic salary ──
            $unpaidDays = (float) DB::table('leave_requests as lr')
                ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
                ->where('lr.employee_id', $hrId)
                ->where('lr.status', 'approved')
                ->where('lt.is_paid', false)
                ->whereDate('lr.start_date', '<=', $end)
                ->whereDate('lr.end_date', '>=', $start)
                ->sum('lr.working_days');

            if ($unpaidDays > 0 && $weekdays > 0) {
                $dailyRate = (float) $payslip->basic_salary / $weekdays;
                PayrollEarningsLine::create([
                    'payslip_id'  => $payslip->id,
                    'line_type'   => EarningsLineType::UnpaidLeave->value,
                    'description' => 'Unpaid leave (' . rtrim(rtrim(number_format($unpaidDays, 2), '0'), '.') . ' day(s))',
                    'amount'      => round($dailyRate * $unpaidDays, 2),
                    'quantity'    => $unpaidDays,
                ]);
                $synced++;
            }

            // ── Leave pay-outs in this period → an earning ──
            $payouts = DB::table('leave_payout_records')
                ->where('employee_id', $hrId)
                ->whereBetween('payout_date', [$start->toDateString(), $end->toDateString()])
                ->where(fn ($q) => $q->whereNull('payroll_reference')->orWhere('payroll_reference', $run->ref_number))
                ->get();

            foreach ($payouts as $po) {
                PayrollEarningsLine::create([
                    'payslip_id'  => $payslip->id,
                    'line_type'   => EarningsLineType::LeavePayout->value,
                    'description' => 'Leave pay-out',
                    'amount'      => (float) $po->amount,
                    'quantity'    => 1,
                ]);
                DB::table('leave_payout_records')->where('id', $po->id)->update(['payroll_reference' => $run->ref_number]);
                $synced++;
            }
        }

        // Recompute the whole run through the SARS engine.
        $this->payroll->calculateAllPayslips($run, (int) (auth()->id() ?? 0));

        return ['available' => true, 'synced' => $synced];
    }

    /** Count of weekdays (Mon–Fri) between two dates, inclusive — used for the daily rate. */
    private function weekdaysBetween(Carbon $start, Carbon $end): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! $day->isWeekend()) {
                $count++;
            }
        }
        return $count;
    }
}
