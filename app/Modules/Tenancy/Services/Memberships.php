<?php

namespace App\Modules\Tenancy\Services;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Belonging to several organisations, and moving between them.
 *
 * The rules, in one place:
 *  - A person works in one organisation at a time: users.company_id. Every module, every row-level
 *    policy and every scope already keys off it, so switching changes that one column.
 *  - Roles belong to the membership. Switching stores the roles held in the organisation being left
 *    and puts back the ones held in the organisation being entered — an owner in one business is
 *    never an owner in the next by accident.
 *  - Only an active membership of an organisation that is open (not archived or suspended) can be
 *    entered.
 *  - Nobody is left without an organisation: the membership somebody is working in can only be
 *    removed when they have another one to move to.
 */
class Memberships
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return Collection<int, CompanyMembership> active memberships of open organisations */
    public function for(User $user): Collection
    {
        return $this->untenanted(fn () => CompanyMembership::query()->with('company')
            ->where('user_id', $user->id)->where('status', CompanyMembership::ACTIVE)->get()
            ->filter(fn ($m) => $m->company && ! $m->company->isArchived() && ! $m->company->isSuspended())
            ->sortBy(fn ($m) => mb_strtolower($m->company->name))->values());
    }

    public function isMember(User $user, int $companyId): bool
    {
        if ((int) $user->company_id === $companyId) {
            return true;
        }

        return $this->untenanted(fn () => CompanyMembership::query()->where('user_id', $user->id)
            ->where('company_id', $companyId)->where('status', CompanyMembership::ACTIVE)->exists());
    }

    /** Ids of everybody who may be picked in an organisation: its own people and its members from elsewhere. */
    public function memberIds(int $companyId): Collection
    {
        return $this->untenanted(fn () => CompanyMembership::query()->where('company_id', $companyId)
            ->where('status', CompanyMembership::ACTIVE)->pluck('user_id'));
    }

    /** Make sure a person's own organisation has a membership row (users created after the backfill). */
    public function ensureHome(User $user): void
    {
        if (! $user->company_id) {
            return;
        }
        $this->untenanted(fn () => CompanyMembership::query()->firstOrCreate(
            ['user_id' => $user->id, 'company_id' => $user->company_id],
            ['status' => CompanyMembership::ACTIVE, 'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getDirectPermissions()->pluck('name')->values()->all()],
        ));
    }

    /** Add somebody who already has an account to an organisation, with the roles they will hold there. */
    public function add(User $user, Company $company, array $roles, User $actor): CompanyMembership
    {
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            throw ValidationException::withMessages(['email' => 'Platform administrators already reach every organisation.']);
        }
        $this->ensureHome($user);

        return $this->untenanted(function () use ($user, $company, $roles, $actor) {
            $m = CompanyMembership::query()->firstOrNew(['user_id' => $user->id, 'company_id' => $company->id]);
            $m->fill(['roles' => array_values($roles), 'status' => CompanyMembership::ACTIVE, 'invited_by' => $m->invited_by ?? $actor->id])->save();
            if ((int) $user->company_id === $company->id) {
                $this->applyRoles($user, $m);
            }

            return $m;
        });
    }

    /** Change the roles somebody holds in an organisation (applied at once if they are working in it). */
    public function setRoles(CompanyMembership $m, array $roles): CompanyMembership
    {
        $m->update(['roles' => array_values($roles)]);
        $user = $m->user;
        if ($user && (int) $user->company_id === (int) $m->company_id) {
            $this->applyRoles($user, $m);
        }

        return $m;
    }

    public function suspend(CompanyMembership $m): void
    {
        $user = $m->user;
        if ($user && (int) $user->company_id === (int) $m->company_id) {
            $other = $this->for($user)->first(fn ($x) => (int) $x->company_id !== (int) $m->company_id);
            if (! $other) {
                throw ValidationException::withMessages(['membership' => 'This is the only organisation this person belongs to. Deactivate their account instead.']);
            }
            $this->switchTo($user, (int) $other->company_id);
        }
        $m->update(['status' => CompanyMembership::SUSPENDED]);
    }

    /** Move a person into another of their organisations, taking the right roles with them. */
    public function switchTo(User $user, int $companyId): void
    {
        $target = $this->for($user)->firstWhere('company_id', $companyId);
        if (! $target) {
            throw ValidationException::withMessages(['company' => 'You are not a member of that organisation, or it is closed.']);
        }
        if ((int) $user->company_id === $companyId) {
            return;
        }

        $this->untenanted(function () use ($user, $target) {
            DB::transaction(function () use ($user, $target) {
                // Keep what they hold where they are leaving, so it is there when they come back.
                if ($user->company_id) {
                    CompanyMembership::query()->updateOrCreate(
                        ['user_id' => $user->id, 'company_id' => $user->company_id],
                        ['roles' => $user->getRoleNames()->values()->all(),
                            'permissions' => $user->getDirectPermissions()->pluck('name')->values()->all()],
                    );
                }
                User::withoutGlobalScopes()->whereKey($user->id)->update(['company_id' => $target->company_id]);
                $user->company_id = $target->company_id;
                $this->applyRoles($user, $target);
                $target->update(['last_used_at' => now()]);
            });
        });
    }

    private function applyRoles(User $user, CompanyMembership $m): void
    {
        $roles = \Spatie\Permission\Models\Role::query()->whereIn('name', $m->roles ?? [])->where('guard_name', 'web')->get();
        $user->syncRoles($roles);
        $perms = \Spatie\Permission\Models\Permission::query()->whereIn('name', $m->permissions ?? [])->where('guard_name', 'web')->get();
        $user->syncPermissions($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    private function untenanted(callable $fn): mixed
    {
        return $this->tenants->run(null, $fn);
    }
}
