<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SickLeaveCertificateRule
{
    public function __construct(
        private readonly WorkingDaysCalculator $calculator,
    ) {}

    /**
     * Returns whether a medical certificate is required for this sick leave request.
     */
    public function isCertificateRequired(HRPerson $employee, string $startDate, string $endDate): array
    {
        $required = false;
        $reasons  = [];

        $workingDays = $this->calculator->calculate($startDate, $endDate);
        $start       = Carbon::parse($startDate);
        $end         = Carbon::parse($endDate);

        // Rule 1: two or more consecutive days of sick leave require a medical certificate.
        if ($workingDays >= 2) {
            $required  = true;
            $reasons[] = 'Sick leave of two or more consecutive days requires a medical certificate.';
        }

        // Rule 2: Monday/Friday rule — a single day adjacent to a weekend requires a certificate.
        $touchesMonOrFri = $start->isMonday() || $end->isFriday()
            || $start->isFriday() || $end->isMonday();

        if ($workingDays < 2 && $touchesMonOrFri) {
            $required  = true;
            $reasons[] = 'Sick leave on a Monday or Friday requires a medical certificate.';
        }

        // Rule 3: 8-week rolling absence threshold (BCEA §23(3))
        $rollingTotal = $this->rollingAbsenceHours($employee->id, $startDate);
        $requestHours = $workingDays * 8;

        if (($rollingTotal + $requestHours) > (6 * 8)) { // 6 days in 8 weeks
            $required  = true;
            $reasons[] = 'BCEA §23(3): Sick leave in this 8-week cycle exceeds 6 days — medical certificate required.';
        }

        return [
            'required' => $required,
            'reasons'  => $reasons,
        ];
    }

    /**
     * Total sick leave hours taken in the rolling 8-week window ending on startDate.
     */
    private function rollingAbsenceHours(int $hrPersonId, string $referenceDate): float
    {
        $windowStart = Carbon::parse($referenceDate)->subWeeks(8)->format('Y-m-d');

        $hours = DB::table('leave_requests')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_requests.employee_id', $hrPersonId)
            ->whereIn('leave_requests.status', ['approved', 'pending', 'pending_manager', 'pending_hr'])
            ->where('leave_types.statutory_type', 'sick')
            ->where('leave_requests.start_date', '>=', $windowStart)
            ->where('leave_requests.start_date', '<',  $referenceDate)
            ->whereNull('leave_requests.deleted_at')
            ->sum('leave_requests.working_hours');

        return (float) $hours;
    }
}
