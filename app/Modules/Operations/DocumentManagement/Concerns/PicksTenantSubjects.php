<?php

namespace App\Modules\Operations\DocumentManagement\Concerns;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Support\ResolvesTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people and departments a screen may offer to choose between.
 *
 * ── Who counts as a person ───────────────────────────────────────────────────
 *
 * Staff, from `hr_people`. Not every `users` row is somebody you can ask for a document: the table
 * also holds service accounts, portal logins for candidates and suppliers, and accounts made for a
 * module and never attached to anybody. Offering those in a "who to ask" list produces requests
 * addressed to nobody, and the person who sent one waits for a reply that cannot come.
 *
 * An employment record is what makes somebody askable, so the join is an inner one and both ends
 * have to be live — a person still employed, and an account that can still sign in.
 *
 * ── Which company ────────────────────────────────────────────────────────────
 *
 * `User` carries a global scope and it steps aside in exactly the case that matters: a platform
 * administrator has no CurrentCompany, and the model exempts them by design even once they have
 * entered a tenant. The company is therefore named explicitly here rather than left to a scope that
 * is not applied.
 *
 * Where a company resolves — an ordinary user, or an administrator who has entered a tenant — the
 * list is that company's staff and nobody else's. Where none resolves the reader is a platform
 * administrator operating above tenancy, and this module already shows them every document in the
 * installation; withholding the names of the people those documents belong to protects nothing and
 * leaves every picker empty for the one account that administers the place.
 */
trait PicksTenantSubjects
{
    use ResolvesTenant;

    /**
     * People this screen may offer.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    protected function tenantPeople(?Request $request = null, int $limit = 500): array
    {
        $companyId = $this->companyId($this->currentRequest($request));

        return User::query()
            ->select('users.*')
            /*
             * Inner, so only people with an employment record are offered. `users` also holds
             * service accounts and portal logins, and a request addressed to one of those is a
             * request nobody will ever answer.
             */
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->where('hr_people.status', 1)
            /*
             * Named explicitly rather than left to the global scope, which is precisely the thing
             * that is not applied for a platform administrator.
             *
             * On self-hosted, a person with no company still belongs to the one company there is.
             * `users.company_id` is stamped from whoever was signed in at the time, so the first
             * administrator — the one who installed the thing — usually has none, and a strict
             * equality quietly leaves them out of every picker on the system. There is no
             * ambiguity to protect against on an install with a single tenant, which is the same
             * reasoning ResolvesTenant already applies one level up.
             *
             * Not extended to SaaS, where it would be the opposite of safe: a user with no company
             * there is a platform administrator who belongs to no tenant, and showing their name
             * in every tenant's picker is a leak rather than a convenience.
             */
            ->when($companyId, fn ($query) => Edition::isSelfHosted()
                ? $query->where(fn ($q) => $q->where('users.company_id', $companyId)->orWhereNull('users.company_id'))
                : $query->where('users.company_id', $companyId))
            ->with('person:id,user_id,first_name,surname')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $this->personName($user),
                // E-Sign addresses the envelope to this, so it comes from the user record rather
                // than from whatever a form posted alongside their id.
                'email' => (string) $user->email,
            ])
            ->filter(fn (array $row) => $row['name'] !== '')
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Departments this screen may offer.
     *
     * @return list<array{id: int, name: string}>
     */
    protected function tenantDepartments(?Request $request = null): array
    {
        $companyId = $this->companyId($this->currentRequest($request));

        return DB::table('company_units')
            ->where('type', 'department')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();
    }

    /**
     * One person, if this request is entitled to see them.
     *
     * The same question in a narrower form: without the company constraint an administrator inside
     * one tenant could paste another customer's user id into the query string and be shown that
     * person's access.
     */
    protected function tenantUser(?Request $request, ?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $companyId = $this->companyId($this->currentRequest($request));

        return User::query()
            ->with('person')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->find($userId);
    }

    /**
     * A person's name.
     *
     * From `hr_people`, because there is no `users.name` column on this platform. The email is a
     * last resort rather than a fallback anybody should be seeing: an account with an employment
     * record and no name on it is a data problem, and showing the address at least leaves the row
     * selectable instead of blank.
     */
    private function personName(User $user): string
    {
        $person = $user->person ?? null;

        return trim(($person?->first_name ?? '') . ' ' . ($person?->surname ?? '')) ?: (string) $user->email;
    }

    /**
     * The request being served, whether or not the caller had one to hand.
     *
     * Controller methods bound to a route do not all take a Request — `show(Document $document)` is
     * the ordinary shape in this module — and a signature that quietly received null would produce
     * exactly the unscoped list this trait exists to prevent.
     */
    private function currentRequest(?Request $request): Request
    {
        return $request ?? request();
    }
}
