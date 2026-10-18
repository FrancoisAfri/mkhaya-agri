<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\AvailabilityResult;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * People, when HR is not installed (PM-1300b).
 *
 * PM keeps its own list in `pm_project_members` — a name, an email, and the role they hold. That is
 * enough to plan work, assign it and record who did it, which is the whole requirement for
 * standalone operation.
 *
 * What it cannot do is know about leave, and it says so rather than guessing: `supportsLeave()`
 * returns false and availability comes back "available, nothing checked". A screen that reported
 * someone as free on that basis would be inventing an assurance the system never made.
 */
class LocalEmployeeProvider implements EmployeeProvider
{
    public function search(string $query, int $limit = 20): array
    {
        return ProjectMember::query()
            ->active()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('display_name', 'ilike', '%' . $query . '%')
                ->orWhere('email', 'ilike', '%' . $query . '%')))
            ->limit($limit)
            ->get()
            ->map(fn (ProjectMember $m) => $this->present($m))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $member = ProjectMember::where('uuid', $id)->first();

        return $member ? $this->present($member) : null;
    }

    /**
     * PM knows its own bookings and nothing else. Reported honestly: available, with an empty
     * `checked` list, so the caller can qualify what it tells the user.
     */
    public function isAvailable(string $id, string $from, string $to): AvailabilityResult
    {
        return AvailabilityResult::unknown();
    }

    public function supportsLeave(): bool
    {
        return false;
    }

    /**
     * PM has no payroll, so it has no rate.
     *
     * Null rather than a default: the rate is typed on the timesheet when there is nothing
     * authoritative to read, and inventing one here would silently cost every project at a number
     * nobody chose.
     */
    public function hourlyRateFor(string $id): ?float
    {
        return null;
    }

    /** Null, not zero — PM records booked hours, and has no idea who was actually on site. */
    public function attendedHours(string $id, string $from, string $to): ?float
    {
        return null;
    }

    /** No competency register without a learning module; an empty list means "nothing known". */
    public function qualificationsFor(string $id): array
    {
        return [];
    }

    private function present(ProjectMember $member): ProvidedRecord
    {
        return new ProvidedRecord(
            id: $member->uuid,
            label: $member->display_name ?: ($member->email ?: 'Unnamed member'),
            source: 'local',
            email: $member->email,
            meta: ['role_key' => $member->role_key, 'project_id' => $member->project_id],
        );
    }
}
