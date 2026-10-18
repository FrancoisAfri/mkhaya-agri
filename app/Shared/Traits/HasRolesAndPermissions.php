<?php

namespace App\Shared\Traits;

use App\Core\Role\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Trait HasRolesAndPermissions
 *
 * Provides a clean, expressive API for interacting with Spatie's role
 * and permission system. Intended for use in Service classes or Models
 * that need to manage authorization concerns.
 *
 * Usage: use HasRolesAndPermissions;
 */
trait HasRolesAndPermissions
{
    // -------------------------------------------------------------------------
    // ROLE RESOLUTION
    // -------------------------------------------------------------------------

    /**
     * Resolve a Role by ID or name. Throws if not found.
     */
    protected function resolveRole(int|string $identifier): Role
    {
        $role = is_int($identifier)
            ? Role::find($identifier)
            : Role::findByName($identifier);

        if (!$role) {
            throw new NotFoundHttpException("Role [{$identifier}] not found.");
        }

        return $role;
    }

    /**
     * Resolve a Permission by ID or name. Throws if not found.
     */
    protected function resolvePermission(int|string $identifier): Permission
    {
        try {
            return is_int($identifier)
                ? Permission::findOrFail($identifier)
                : Permission::findByName($identifier);
        } catch (\Exception) {
            throw new NotFoundHttpException("Permission [{$identifier}] not found.");
        }
    }

    // -------------------------------------------------------------------------
    // ROLE QUERIES
    // -------------------------------------------------------------------------

    /**
     * Return all roles, optionally with their permissions eager-loaded.
     */
    protected function getAllRoles(bool $withPermissions = false): Collection
    {
        $query = Role::query();

        if ($withPermissions) {
            $query->with('permissions');
        }

        return $query->get();
    }

    /**
     * Check whether a role (by ID or name) exists.
     */
    protected function roleExists(int|string $identifier): bool
    {
        return is_int($identifier)
            ? Role::where('id', $identifier)->exists()
            : Role::where('name', $identifier)->exists();
    }

    // -------------------------------------------------------------------------
    // ROLE MUTATIONS
    // -------------------------------------------------------------------------

    /**
     * Create a new role. Optionally assign permissions immediately.
     *
     * @param array{name: string, guard_name?: string} $data
     * @param array<int|string> $permissions Permission IDs or names
     */
    protected function createRole(array $data, array $permissions = []): Role
    {
        $role = Role::create($data);

        if (!empty($permissions)) {
            $role->syncPermissions($permissions);
        }

        return $role->load('permissions');
    }

    /**
     * Update an existing role's attributes.
     *
     * @param array{name?: string, guard_name?: string} $data
     */
    protected function updateRole(int|string $identifier, array $data): Role
    {
        $role = $this->resolveRole($identifier);
        $role->update($data);

        return $role->fresh('permissions');
    }

    /**
     * Delete a role by ID or name.
     */
    protected function deleteRole(int|string $identifier): void
    {
        $this->resolveRole($identifier)->delete();
    }

    // -------------------------------------------------------------------------
    // PERMISSION SYNC / ASSIGNMENT
    // -------------------------------------------------------------------------

    /**
     * Sync a role's permissions, replacing all existing ones.
     *
     * @param array<int|string> $permissions Permission IDs or names
     */
    protected function syncRolePermissions(int|string $identifier, array $permissions): Role
    {
        $role = $this->resolveRole($identifier);
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    /**
     * Attach additional permissions to a role without removing existing ones.
     *
     * @param array<int|string> $permissions
     */
    protected function giveRolePermissions(int|string $identifier, array $permissions): Role
    {
        $role = $this->resolveRole($identifier);
        $role->givePermissionTo($permissions);

        return $role->load('permissions');
    }

    /**
     * Revoke specific permissions from a role.
     *
     * @param array<int|string> $permissions
     */
    protected function revokeRolePermissions(int|string $identifier, array $permissions): Role
    {
        $role = $this->resolveRole($identifier);

        foreach ($permissions as $permission) {
            $role->revokePermissionTo($permission);
        }

        return $role->load('permissions');
    }

    // -------------------------------------------------------------------------
    // MODEL-LEVEL HELPERS (for use on a User/Model instance)
    // -------------------------------------------------------------------------

    /**
     * Assign one or more roles to the calling model ($this).
     * The model must use Spatie's HasRoles trait.
     *
     * @param array<int|string>|string $roles
     */
    protected function assignRolesToModel(mixed $model, array|string $roles): void
    {
        $model->assignRole($roles);
    }

    /**
     * Remove one or more roles from the given model.
     *
     * @param array<int|string>|string $roles
     */
    protected function removeRolesFromModel(mixed $model, array|string $roles): void
    {
        $model->removeRole($roles);
    }

    /**
     * Sync roles on the given model.
     *
     * @param array<int|string> $roles
     */
    protected function syncModelRoles(mixed $model, array $roles): void
    {
        $model->syncRoles($roles);
    }

    // -------------------------------------------------------------------------
    // PERMISSION QUERIES
    // -------------------------------------------------------------------------

    /**
     * Return all permissions, optionally grouped by their role.
     */
    protected function getAllPermissions(bool $withRoles = false): Collection
    {
        $query = Permission::query();

        if ($withRoles) {
            $query->with('roles');
        }

        return $query->get();
    }

    /**
     * Get all permissions belonging to a specific role.
     */
    protected function getPermissionsForRole(int|string $identifier): Collection
    {
        return $this->resolveRole($identifier)->permissions;
    }
}
