<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use Illuminate\Support\Collection;

/**
 * Who holds each sign-off slot for this tenant, and therefore who may make each move.
 *
 * A slot can be filled two ways — by naming users directly, or by naming a role whose holders fill
 * it. Both, because clients arrange themselves differently and forcing one shape produces a role map
 * that is either always stale or never used.
 *
 * The unconfigured case is the one worth being careful about. A tenant that has not mapped anything
 * falls back to the module's existing permission (`suppliers.approve`) for decision slots — the same
 * people who can approve a supplier today. That keeps a fresh installation working instead of
 * deadlocked, which matters because a workflow nobody can advance gets bypassed by the second day.
 * The separation-of-duties rule still applies to the fallback.
 */
class RoleMap
{
    /** @var array<string, Collection<int, User>>|null */
    private ?array $cache = null;

    /**
     * May this user act in this slot?
     *
     * Senior slots satisfy junior ones, so an Executive can do a Procurement Officer's work — a rule
     * whose absence sends a small client straight round the system.
     */
    public function can(?User $user, string $slot): bool
    {
        if (! $user) {
            return false;
        }

        // An unrecognised slot is never satisfiable. Without this a typo in a slot name falls through
        // to the unconfigured-slot fallback and quietly grants whoever asked.
        if (! RoleSlot::exists($slot)) {
            return false;
        }

        $configured = SupplierSetting::query()->first()->dd_roles ?? [];

        foreach (RoleSlot::satisfiedBy($slot) as $candidate) {
            $entry = $configured[$candidate] ?? [];

            // Unconfigured slot: ask the user directly rather than looking them up in a list built
            // earlier. Building a list means loading every user on the tenant to answer one question,
            // and — the bug that produced this — a list built at the start of a request does not
            // contain somebody added during it.
            if ($entry === []) {
                if ($this->hasFallbackPermission($user, $candidate)) {
                    return true;
                }

                continue;
            }

            if (in_array((int) $user->id, array_map('intval', $entry['users'] ?? []), true)) {
                return true;
            }

            if (($entry['roles'] ?? []) !== [] && $this->hasAnyRole($user, $entry['roles'])) {
                return true;
            }
        }

        return false;
    }

    private function hasFallbackPermission(User $user, string $slot): bool
    {
        try {
            return $user->can($slot === RoleSlot::PROCUREMENT_OFFICER ? 'suppliers.update' : 'suppliers.approve');
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasAnyRole(User $user, array $roles): bool
    {
        try {
            return $user->hasAnyRole($roles);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Everybody who fills a slot, for notifying them that something is waiting. */
    public function holders(string $slot): Collection
    {
        $this->cache ??= $this->build();

        return $this->cache[$slot] ?? collect();
    }

    /** Everybody who could act on a slot, including the seniors who inherit it. */
    public function anyoneFor(string $slot): Collection
    {
        return collect(RoleSlot::satisfiedBy($slot))
            ->flatMap(fn (string $candidate) => $this->holders($candidate))
            ->unique('id')
            ->values();
    }

    /** The configuration as stored, for the settings screen. */
    public function configuration(): array
    {
        $configured = SupplierSetting::query()->first()->dd_roles ?? [];

        return collect(RoleSlot::all())
            ->map(fn (array $meta, string $slot) => [
                'slot' => $slot,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'user_ids' => array_values(array_map('intval', $configured[$slot]['users'] ?? [])),
                'roles' => array_values($configured[$slot]['roles'] ?? []),
                'holders' => $this->holders($slot)->map(fn (User $u) => [
                    'id' => $u->id,
                    'email' => $u->email,
                ])->values(),
                // Said plainly, because a slot silently falling back is how a client believes they
                // have segregation they do not have.
                'is_fallback' => ($configured[$slot] ?? []) === [],
            ])
            ->values()
            ->all();
    }

    public function save(array $map): void
    {
        $clean = [];

        foreach (RoleSlot::all() as $slot => $meta) {
            $users = array_values(array_filter(array_map('intval', $map[$slot]['users'] ?? [])));
            $roles = array_values(array_filter(array_map('strval', $map[$slot]['roles'] ?? [])));

            if ($users !== [] || $roles !== []) {
                $clean[$slot] = ['users' => $users, 'roles' => $roles];
            }
        }

        SupplierSetting::query()->firstOrCreate([])->update(['dd_roles' => $clean]);

        $this->cache = null;
    }

    /** @return array<string, Collection<int, User>> */
    private function build(): array
    {
        $configured = SupplierSetting::query()->first()->dd_roles ?? [];
        $map = [];

        foreach (RoleSlot::all() as $slot => $meta) {
            $entry = $configured[$slot] ?? [];

            if ($entry === []) {
                $map[$slot] = $this->fallbackHolders($slot);

                continue;
            }

            $users = User::query()->whereIn('id', $entry['users'] ?? [])->get();

            if (($entry['roles'] ?? []) !== []) {
                // Tenant-scoped by the User model's own company scope, so naming a global role does
                // not pull in another tenant's people.
                $byRole = User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', $entry['roles']))->get();
                $users = $users->merge($byRole)->unique('id');
            }

            $map[$slot] = $users->values();
        }

        return $map;
    }

    /**
     * Who fills a slot nobody has configured.
     *
     * Decision slots fall back to the module's existing approvers, so a tenant that installs the
     * add-on can work cases on day one. Officer work falls back to anyone who can update a supplier,
     * which is what raising one already requires.
     */
    private function fallbackHolders(string $slot): Collection
    {
        $permission = $slot === RoleSlot::PROCUREMENT_OFFICER ? 'suppliers.update' : 'suppliers.approve';

        return User::query()->get()->filter(function (User $user) use ($permission) {
            try {
                return $user->can($permission);
            } catch (\Throwable) {
                return false;
            }
        })->values();
    }
}
