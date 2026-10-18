<?php

namespace App\Modules\Dashboard\Services;

use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\CeoMessage;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use App\Modules\Communication\Resources\Models\CorporateBranding;
use App\Modules\Communication\Resources\Models\Resource;
use App\Modules\HR\Models\HrPeopleChange;
use App\Modules\HR\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardService
{


    public function getDashboardData(): array
    {
        $id   = Auth::id();
        $user = User::with('companies')->find($id);

        return [
            'user'          => $user?->person,
            'approvalAlert' => $this->getApprovalAlert($user, $id),
            'profileAlert'  => $this->getProfileAlert($user, $id),
        ];
    }

    private function getApprovalAlert($user, $id)
    {
        if (!$user || !$user->companies()->exists()) {
            return null;
        }

        $pendingCount = HrPeopleChange::where('is_approved', false)->count();
        $sessionKey   = "approval_alert_shown_{$id}";

        if ($pendingCount > 0 && !session()->has($sessionKey)) {
            session()->put($sessionKey, true);

            return [
                'show'         => true,
                'message'      => $pendingCount === 1
                    ? 'An employee submitted a request to modify their profile.'
                    : "{$pendingCount} employees have submitted requests to modify their profiles.",
                'pendingCount' => $pendingCount,
            ];
        }

        return null;
    }

    private function getProfileAlert($user, $id)
    {
        $hr         = $user?->person;
        $completion = $hr?->profile_completion ?? 0;
        $sessionKey = "profile_alert_shown_{$id}";

        if ($completion < 80 && !session()->has($sessionKey)) {
            session()->put($sessionKey, true);

            return [
                'show'       => true,
                'completion' => $completion,
                'message'    => "Your profile is only {$completion}% complete. Please complete it to unlock all features.",
            ];
        }

        return null;
    }



    /*
    |=========================================================================
    | 🎨 CORPORATE BRANDING
    |=========================================================================
    */

    public function getCorporateBranding()
    {
        // Priority 1: global company-wide active branding (no company_unit)
        $global = CorporateBranding::withoutTrashed()
            ->where('is_active', true)
            ->whereNull('company_unit_id')
            ->with(['logoLight', 'logoDark', 'favicon'])
            ->latest('updated_at')
            ->first();

        if ($global) {
            return $global;
        }

        // Fallback: any active branding (most recently updated)
        return CorporateBranding::withoutTrashed()
            ->where('is_active', true)
            ->with(['logoLight', 'logoDark', 'favicon'])
            ->latest('updated_at')
            ->first();
    }

    /*
    |=========================================================================
    | 📁 RESOURCES
    |=========================================================================
    */



public function getSpotlights(int $limit = 8): Collection
{
    return EmployeeSpotlight::with([
            'employee.person',
            'author.person',
            'companyUnit',
            'assetFile'
        ])
        ->whereDate('month', '<=', now()->startOfMonth())
        ->latest()
        ->limit($limit)
        ->get();
}
    public function getPaginated(
        int    $perPage = 10,
        int    $page    = 1,
        string $search  = '',
    ): LengthAwarePaginator {
        return EmployeeSpotlight::with(['employee.person', 'author.person', 'companyUnit', 'assetFile'])
            ->when($search, fn($q) => $q->where('title', 'like', "%{$search}%"))
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }
    public function getEmployees(): Collection
    {
        return User::with('person')
            ->where('status', 1)
            ->orderBy('id')
            ->get()
            ->map(fn(User $u) => [
                'id'     => $u->id,
                'email'  => $u->email,
                'status' => $u->status,
                'roles'  => $u->roles->map(fn($r) => ['id' => $r->id, 'name' => $r->name]),
                'person' => $u->person ? [
                    'first_name' => $u->person->first_name,
                    'surname'    => $u->person->surname,
                    'uuid'       => $u->person->uuid ?? '',
                    'initial'    => $u->person->initial ?? null,
                ] : null,
            ]);
    }

    /*
    |=========================================================================
    | 🎂 STAFF ANNIVERSARIES
    |=========================================================================
    */

    public function getStaffAnniversaries(): Collection
    {
        $today = Carbon::now();
        $currentMonth = $today->month;
        $currentDay   = $today->day;

        return HRPerson::whereNotNull('date_joined')
            ->where('status', 1)
            ->get()
            ->filter(function (HRPerson $person) use ($today, $currentMonth, $currentDay) {
                $joinedDate = Carbon::parse($person->date_joined);

                // Au moins 1 an d'ancienneté
                if ($joinedDate->diffInYears($today) === 0) {
                    return false;
                }

                // Anniversaire dans le mois courant
                if ($joinedDate->month !== $currentMonth) {
                    return false;
                }

                // Jour >= aujourd'hui (pas dans le passé ce mois-ci)
                if ($joinedDate->day < $currentDay) {
                    return false;
                }

                return true;
            })
            ->map(function (HRPerson $person) use ($today) {
                $joinedDate = Carbon::parse($person->date_joined);

                // Anniversaire cette année (la date exacte ce mois-ci)
                $anniversaryThisYear = $joinedDate->copy()->year($today->year);

                // Nombre d'années QU'IL VA FÊTER (arrondi au prochain anniversaire)
                $yearsToСelebrate = $joinedDate->diffInYears($anniversaryThisYear);

                // Label
                $yearsLabel = $yearsToСelebrate === 1
                    ? '1 year'
                    : "{$yearsToСelebrate} years";

                $title    = $person->title ? "{$person->title} " : '';
                $fullName = trim("{$title}{$person->first_name} {$person->surname}");

                // "is celebrating their 3rd anniversary" style
                $ordinal = match ($yearsToСelebrate) {
                    1       => '1st',
                    2       => '2nd',
                    3       => '3rd',
                    default => "{$yearsToСelebrate}th",
                };

                return [
                    'id'          => $person->id,
                    'title'       => "{$fullName} celebrates work anniversary",
                    'description' => "{$fullName} is celebrating their {$ordinal} work anniversary on "
                        . $anniversaryThisYear->format('d M Y') . ' 🎉',
                    'summary'     => "{$fullName} is celebrating {$yearsLabel} with us 🎂",
                    'type'        => 'Staff Anniversary',
                    'event_date'  => $anniversaryThisYear->toDateTimeString(),
                    'created_at'  => $person->created_at,
                    'person'      => [
                        'first_name'  => $person->first_name,
                        'surname'     => $person->surname,
                        'profile_pic' => $person->profile_pic,
                        'initial'     => (isset($person->first_name[0]) ? strtoupper($person->first_name[0]) : 'A')
                            . (isset($person->surname[0])    ? strtoupper($person->surname[0])    : 'A'),
                    ],
                    'years_to_celebrate' => $yearsToСelebrate,
                    'years_label'        => $yearsLabel,
                    'ordinal'            => $ordinal,
                    'join_date'          => $joinedDate->format('d M Y'),
                ];
            })
            ->sortBy('event_date')
            ->values();
    }

    /*
    |=========================================================================
    | 🏖️ STAFF LEAVES
    |=========================================================================
    */

    public function getLeaves(): Collection
    {
        $today = Carbon::now();
        $oneMonthFromNow = $today->copy()->addMonth();

        return LeaveRequest::with(['employee', 'leaveType'])
            ->where('status', LeaveRequestStatus::Approved->value)
            ->get()
            ->filter(function (LeaveRequest $leave) use ($today) {
                // Only show leaves of active employees
                $employee = $leave->employee;
                return $employee && $employee->status === 1;
            })
            ->partition(function (LeaveRequest $leave) use ($today, $oneMonthFromNow) {
                // Partition into current and upcoming
                $startDate = Carbon::parse($leave->start_date);
                $endDate = Carbon::parse($leave->end_date);

                // Current: leave is happening now
                $isCurrent = $startDate->lte($today) && $endDate->gte($today);

                return $isCurrent;
            })
            ->flatMap(function ($partition, $index) use ($today, $oneMonthFromNow) {
                return $partition->map(function (LeaveRequest $leave) use ($today, $oneMonthFromNow, $index) {
                    $startDate = Carbon::parse($leave->start_date);
                    $endDate = Carbon::parse($leave->end_date);
                    $employee = $leave->employee;

                    // Check if it's current or upcoming
                    $isCurrent = $index === 0; // First partition is current

                    // For upcoming, only include if within 1 month
                    if (!$isCurrent && $startDate->gt($oneMonthFromNow)) {
                        return null;
                    }

                    $fullName = trim(($employee->title ? "{$employee->title} " : "") . "{$employee->first_name} {$employee->surname}");
                    $leaveDays = $leave->working_days ?? ceil($endDate->diffInDays($startDate) + 1);

                    $status = $isCurrent ? 'on_leave' : 'upcoming';
                    $daysRemaining = $isCurrent ? $endDate->diffInDays($today) + 1 : null;

                    return [
                        'id'            => $leave->id,
                        'title'         => "{$fullName} is " . ($isCurrent ? 'on Leave' : 'taking Leave soon'),
                        'description'   => "{$fullName} is " . ($isCurrent ? 'currently' : 'planning to be') . " away for {$leaveDays} day" . ($leaveDays !== 1 ? 's' : '') . " from " . $startDate->format('d M Y') . " to " . $endDate->format('d M Y'),
                        'summary'       => "{$fullName} - " . $startDate->format('d M Y') . " to " . $endDate->format('d M Y') . " ({$leaveDays} day" . ($leaveDays !== 1 ? 's' : '') . ")",
                        'type'          => 'Staff Leave',
                        'leave_status'  => $status,
                        'start_date'    => $startDate->toDateTimeString(),
                        'end_date'      => $endDate->toDateTimeString(),
                        'leave_days'    => $leaveDays,
                        'days_remaining' => $daysRemaining,
                        'created_at'    => $leave->created_at,
                        'person'        => [
                            'first_name'  => $employee->first_name,
                            'surname'     => $employee->surname,
                            'profile_pic' => $employee->profile_pic,
                            'initial'     => (isset($employee->first_name[0]) ? $employee->first_name[0] : 'A') . (isset($employee->surname[0]) ? $employee->surname[0] : 'A'),
                        ],
                        'leave_type'    => $leave->leaveType?->name ?? 'Leave',
                    ];
                })->filter(fn($item) => $item !== null); // Remove null items (leaves outside 1 month window)
            })
            ->sortBy('start_date')
            ->values();
    }
}
