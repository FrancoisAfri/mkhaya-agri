<?php

namespace App\Modules\HR\Support;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Support\ResolvesTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people a performance or recruitment screen may offer to choose between.
 *
 * ── Why the join is a left one ───────────────────────────────────────────────
 *
 * Document Management asks a related question — who can I send this to — and answers it with an
 * inner join, because a request addressed to a service account is a request nobody answers. Here
 * the question is who can be appraised, nominated, given a KPI or shortlisted, and the answer
 * cannot be "only people who already have a personnel record": on a live tenant with 35 accounts
 * and 6 personnel records, every picker built that way offers six names and looks broken.
 *
 * That is the reported fault. Somebody adds their staff as logins, opens a performance screen, and
 * the dropdown is empty — because creating a user does not create an employment record, and the
 * screens were reading the latter.
 *
 * So the account is what makes somebody offerable and the personnel record only supplies a better
 * name. Where there is none, the email stands in, which is a name somebody recognises rather than
 * a blank.
 *
 * ── Which company ────────────────────────────────────────────────────────────
 *
 * Named explicitly, never left to `User`'s global scope. That scope steps aside for a platform
 * administrator by design, which is exactly the account these screens were most often read by —
 * and several of these lists were raw `DB::table('users')` queries where no scope applied at all.
 * `users` carries no row-level security to catch that, so those pickers offered every tenant's
 * people to every other tenant.
 *
 * The self-hosted allowance mirrors ResolvesTenant: on a single-tenant install the first
 * administrator usually has no company stamped on them, and a strict equality leaves the person
 * who installed the system out of every list on it. On SaaS the same allowance would be a leak, so
 * it is not extended there.
 */
trait PicksTenantPeople
{
    use ResolvesTenant;

    /**
     * Everybody this tenant may choose between, best name first.
     *
     * @return list<array{id: int, name: string, email: string, has_record: bool}>
     */
    protected function tenantPeople(?Request $request = null, int $limit = 1000): array
    {
        $companyId = $this->companyId($request ?? request());

        return User::query()
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            /*
             * Only an explicit zero removes somebody.
             *
             * Both status columns are nullable and neither has a default, and on a live tenant
             * most personnel records carry no status at all — 7 of 13 here. Reading "not 1" as
             * "not active" hides those people, which is the same empty dropdown this trait exists
             * to fix, arrived at from the other direction. A blank is a value nobody set, not a
             * termination.
             */
            ->where(fn ($q) => $q->whereNull('users.status')->orWhere('users.status', '!=', 0))
            ->where(fn ($q) => $q->whereNull('hr_people.status')->orWhere('hr_people.status', '!=', 0))
            ->when($companyId, fn ($query) => Edition::isSelfHosted()
                ? $query->where(fn ($q) => $q->where('users.company_id', $companyId)->orWhereNull('users.company_id'))
                : $query->where('users.company_id', $companyId))
            ->select([
                'users.id',
                'users.email',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(hr_people.first_name,''),' ',COALESCE(hr_people.surname,''))), '') AS person_name"),
                DB::raw('hr_people.id AS person_id'),
            ])
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->person_name ?: (string) $row->email,
                'email' => (string) $row->email,
                // Lets a screen say "no personnel record yet" rather than silently treating an
                // account as an employee.
                'has_record' => $row->person_id !== null,
            ])
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** The same list keyed for a simple `{id, name}` picker. */
    protected function tenantPeopleOptions(?Request $request = null, int $limit = 1000): array
    {
        return array_map(
            fn (array $p) => ['id' => $p['id'], 'name' => $p['name']],
            $this->tenantPeople($request, $limit),
        );
    }

    /**
     * Just the ids, for a loop that writes a record per person.
     *
     * A bulk action that iterated `users` unscoped wrote a row for every account on
     * the platform, so one tenant pressing "build all" created records against
     * another tenant's staff.
     */
    protected function tenantPeopleIds(?Request $request = null, int $limit = 10000): array
    {
        return array_map(
            fn (array $person) => $person['id'],
            $this->tenantPeople($request, $limit),
        );
    }

    /**
     * One person's name, or a placeholder when the id is not this tenant's to read.
     */
    protected function tenantPersonName(int $userId, ?Request $request = null): string
    {
        $companyId = $this->companyId($request ?? request());

        $row = User::query()
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.id', $userId)
            ->when($companyId, fn ($query) => Edition::isSelfHosted()
                ? $query->where(fn ($q) => $q->where('users.company_id', $companyId)->orWhereNull('users.company_id'))
                : $query->where('users.company_id', $companyId))
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(hr_people.first_name,''),' ',COALESCE(hr_people.surname,''))), ''), users.email) AS name")
            ->first();

        return $row?->name ?: "User #{$userId}";
    }
}
