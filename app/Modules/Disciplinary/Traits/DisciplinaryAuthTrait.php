<?php

namespace App\Modules\Disciplinary\Traits;

use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use Illuminate\Support\Facades\DB;

trait DisciplinaryAuthTrait
{
    // Roles that have full admin control over the module
    protected const DISC_ADMIN_ROLES = ['super admin', 'administrator', 'HR'];

    protected function isDiscAdmin(): bool
    {
        return auth()->user()->hasRole(self::DISC_ADMIN_ROLES);
    }

    // ── Case role ─────────────────────────────────────────────────────────────
    // admin_hr  → full control
    // investigator → view + notes only
    // chairperson  → view + notes only
    // employee     → view only (their own case)
    // manager      → view only (their direct report's case)
    // none         → no access (abort 403)

    protected function caseViewerRole(DisciplinaryCase $case): string
    {
        $userId = auth()->id();

        if ($this->isDiscAdmin())               return 'admin_hr';
        if ($case->investigator_id === $userId) return 'investigator';
        if ($case->chairperson_id  === $userId) return 'chairperson';
        if ($case->employee_id     === $userId) return 'employee';
        if ($this->isDirectManagerOf($case->employee_id)) return 'manager';

        return 'none';
    }

    // ── Incident role ─────────────────────────────────────────────────────────
    // admin_hr     → full control
    // investigator → view + evidence/witness actions
    // reporter     → view only (their own report)
    // manager      → view only (their direct report is accused)
    // none         → no access

    protected function incidentViewerRole(DisciplinaryIncident $incident): string
    {
        $userId = auth()->id();

        if ($this->isDiscAdmin()) return 'admin_hr';
        if ($incident->investigator_id === $userId) return 'investigator';
        if (! $incident->is_anonymous && $incident->reporter_id === $userId) return 'reporter';
        if ($this->isManagerOfAnyAccused($incident)) return 'manager';

        return 'none';
    }

    // ── Case index query scope ────────────────────────────────────────────────
    // Returns a query scoped to cases the current user is allowed to see.

    protected function scopedCaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $userId = auth()->id();

        if ($this->isDiscAdmin()) {
            return DisciplinaryCase::query();
        }

        $subordinateIds = $this->getSubordinateUserIds($userId);

        return DisciplinaryCase::where(function ($q) use ($userId, $subordinateIds) {
            $q->where('employee_id',    $userId)
              ->orWhere('investigator_id', $userId)
              ->orWhere('chairperson_id',  $userId);
            if (! empty($subordinateIds)) {
                $q->orWhereIn('employee_id', $subordinateIds);
            }
        });
    }

    // ── Incident index query scope ────────────────────────────────────────────

    protected function scopedIncidentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $userId = auth()->id();

        if ($this->isDiscAdmin()) {
            return DisciplinaryIncident::query();
        }

        $subordinateIds = $this->getSubordinateUserIds($userId);

        return DisciplinaryIncident::where(function ($q) use ($userId, $subordinateIds) {
            $q->where('reporter_id',    $userId)
              ->orWhere('investigator_id', $userId);
            // accused: user's employee_id appears in disciplinary_accused
            $q->orWhereHas('accused', fn($a) => $a->where('employee_id', $userId));
            if (! empty($subordinateIds)) {
                $q->orWhereHas('accused', fn($a) => $a->whereIn('employee_id', $subordinateIds));
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isDirectManagerOf(?int $employeeId): bool
    {
        if (! $employeeId) return false;

        $myPersonId = DB::table('hr_people')->where('user_id', auth()->id())->value('id');
        if (! $myPersonId) return false;

        $empPersonId = DB::table('hr_people')->where('user_id', $employeeId)->value('id');
        if (! $empPersonId) return false;

        return DB::table('hr_people')
            ->where('id', $empPersonId)
            ->where('manager_id', $myPersonId)
            ->exists();
    }

    private function isManagerOfAnyAccused(DisciplinaryIncident $incident): bool
    {
        $myPersonId = DB::table('hr_people')->where('user_id', auth()->id())->value('id');
        if (! $myPersonId) return false;

        $accusedUserIds = $incident->accused->pluck('employee_id')->filter()->values();
        if ($accusedUserIds->isEmpty()) return false;

        $accusedPersonIds = DB::table('hr_people')
            ->whereIn('user_id', $accusedUserIds)
            ->pluck('id');

        return DB::table('hr_people')
            ->whereIn('id', $accusedPersonIds)
            ->where('manager_id', $myPersonId)
            ->exists();
    }

    private function getSubordinateUserIds(int $userId): array
    {
        $myPersonId = DB::table('hr_people')->where('user_id', $userId)->value('id');
        if (! $myPersonId) return [];

        $personIds = [$myPersonId];
        $queue     = [$myPersonId];

        while (! empty($queue)) {
            $children = DB::table('hr_people')
                ->whereIn('manager_id', $queue)
                ->pluck('id')
                ->toArray();

            $newChildren = array_diff($children, $personIds);
            if (empty($newChildren)) break;

            $personIds = array_merge($personIds, $newChildren);
            $queue     = array_values($newChildren);
        }

        return DB::table('hr_people')
            ->whereIn('id', $personIds)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->toArray();
    }
}
