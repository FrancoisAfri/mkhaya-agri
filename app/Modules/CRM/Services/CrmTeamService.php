<?php

namespace App\Modules\CRM\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves sales teams from the HR reporting hierarchy (hr_people.manager_id),
 * so a sales manager's dashboard shows their direct reports and an executive's
 * dashboard spans everyone.
 */
class CrmTeamService
{
    /**
     * User IDs reporting to a manager (their direct reports). Falls back to an
     * empty list when the manager has no HR person record / no reports.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function teamOf(int $managerUserId): array
    {
        $managerPersonId = DB::table('hr_people')->where('user_id', $managerUserId)->value('id');
        if (! $managerPersonId) {
            return [];
        }

        return DB::table('hr_people')
            ->join('users', 'users.id', '=', 'hr_people.user_id')
            ->where('hr_people.manager_id', $managerPersonId)
            ->whereNotNull('hr_people.user_id')
            ->select('users.id', DB::raw("CONCAT(hr_people.first_name, ' ', hr_people.surname) as name"))
            ->orderBy('hr_people.first_name')
            ->get()
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->name)])
            ->all();
    }

    /**
     * Every user id in a manager's reporting tree — direct reports and their
     * reports, all the way down — including the manager themselves.
     *
     * @return array<int, int>
     */
    public function subtreeOf(int $managerUserId): array
    {
        $personId = DB::table('hr_people')->where('user_id', $managerUserId)->value('id');
        if (! $personId) {
            return [$managerUserId];
        }

        // Map manager_person_id => [child person rows] for an in-memory walk.
        $people = DB::table('hr_people')
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'manager_id']);

        $childrenByManager = [];
        foreach ($people as $p) {
            $childrenByManager[$p->manager_id][] = $p;
        }

        $userIds  = [$managerUserId];
        $queue    = [$personId];
        $seen     = [$personId => true];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($childrenByManager[$current] ?? [] as $child) {
                if (isset($seen[$child->id])) {
                    continue; // guard against cycles
                }
                $seen[$child->id] = true;
                $userIds[] = (int) $child->user_id;
                $queue[]   = $child->id;
            }
        }

        return array_values(array_unique($userIds));
    }

    /** The manager (user id) a given user reports to, or null. */
    public function managerOf(int $userId): ?int
    {
        $managerPersonId = DB::table('hr_people')->where('user_id', $userId)->value('manager_id');
        if (! $managerPersonId) {
            return null;
        }
        $managerUserId = DB::table('hr_people')->where('id', $managerPersonId)->value('user_id');

        return $managerUserId ? (int) $managerUserId : null;
    }

    /** User IDs holding a given role (e.g. sales manager). */
    public function usersWithRole(string $role): array
    {
        try {
            return \App\Core\User\Models\User::role($role)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Everyone who carries a sales target (the sales force) — used by the
     * executive dashboard. Falls back to all active users if no targets set.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function salesForce(): array
    {
        $withTargets = DB::table('crm_targets')->distinct()->pluck('user_id')->all();

        $q = DB::table('hr_people')
            ->join('users', 'users.id', '=', 'hr_people.user_id')
            ->whereNotNull('hr_people.user_id');

        if ($withTargets) {
            $q->whereIn('users.id', $withTargets);
        }

        return $q->select('users.id', DB::raw("CONCAT(hr_people.first_name, ' ', hr_people.surname) as name"))
            ->orderBy('hr_people.first_name')
            ->limit(1000)
            ->get()
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->name)])
            ->all();
    }
}
