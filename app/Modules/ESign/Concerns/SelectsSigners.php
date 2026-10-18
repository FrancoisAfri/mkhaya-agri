<?php

namespace App\Modules\ESign\Concerns;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The colleagues an envelope can be addressed to.
 *
 * Deliberately not PicksTenantSubjects::tenantPeople(). That inner-joins `hr_people`, which is
 * right where it came from — a task assigned to a service account is a task nobody will ever do —
 * and wrong here. Signing is not employment. A director, a bookkeeper, an external accountant with
 * a login, a shareholder: all of them sign things, and none of them necessarily has an employment
 * record. On this database that join offered 6 of 44 active users, and on a company keeping its
 * staff records elsewhere it offers none at all — which reads as "nobody from our organisation is
 * available" on a system full of people.
 *
 * So the rule is having an account and an address, not having a job. Portal logins are still
 * excluded, because a contractor or client signs as an outside party through their own token —
 * that is what the other half of the picker is for.
 */
trait SelectsSigners
{
    use PicksTenantSubjects;

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    protected function selectableSigners(?Request $request = null): array
    {
        /*
         * What "the company" means depends on the edition, and getting it wrong empties the picker
         * on one of them.
         *
         * Standalone: there is one organisation, so nothing is filtered. Users on a self-hosted
         * install commonly carry no company_id at all, while the tenant resolver still answers
         * with a company — so filtering on it drops every one of them and the screen says "nobody
         * from your organisation is available" on a system where everybody is a colleague.
         *
         * Hosted: a company is required. `when($companyId, …)` would simply drop the filter when
         * nothing resolves — a platform administrator who has not switched into a company would be
         * offered every user on the platform, every name and address from every tenant, in a
         * dropdown. An empty picker is a problem somebody reports; that one is not.
         */
        $selfHosted = Edition::isSelfHosted();
        $companyId  = $selfHosted ? null : ($this->companyId($request ?? request()) ?? auth()->user()?->company_id);

        if (! $companyId && ! $selfHosted) {
            return [];
        }

        $external = $this->portalUserIds();

        return User::query()
            ->when($companyId, fn ($q) => $q->where('users.company_id', $companyId))
            ->where('users.status', 1)
            ->whereNotNull('users.email')
            ->when($external !== [], fn ($q) => $q->whereNotIn('users.id', $external))
            ->with('person:id,user_id,first_name,surname')
            ->orderBy('users.email')
            ->limit(1000)
            ->get()
            ->map(fn (User $user) => [
                'id'    => (int) $user->id,
                'name'  => $this->signerName($user),
                'email' => (string) $user->email,
            ])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * A name to show in the list.
     *
     * The employment record when there is one, because "Nomsa Dlamini" is what a colleague is
     * called. Otherwise something readable built from the address — an unnamed row in a signer
     * picker is unpickable, and dropping such rows is how the list came to be empty.
     */
    private function signerName(User $user): string
    {
        $person = $user->person;
        $named  = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));

        if ($named !== '') {
            return $named;
        }

        return Str::of(Str::before((string) $user->email, '@'))
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
    }

    /**
     * Accounts that belong to outside parties.
     *
     * They sign through the token their envelope is sent with, so offering them here would be a
     * second, weaker way of reaching the same person.
     *
     * @return list<int>
     */
    private function portalUserIds(): array
    {
        if (! Schema::hasTable('pm_project_members')) {
            return [];
        }

        return DB::table('pm_project_members')
            ->whereIn('role_key', ['client', 'contractor'])
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
