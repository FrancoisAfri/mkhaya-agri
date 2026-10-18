<?php

namespace App\Core\Role\Repositories;

use App\Core\Role\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function getAll(): Collection
    {
        return Role::with('permissions')->get();
    }

    public function findById(int $id): ?Role
    {
        return Role::with('permissions')->find($id);
    }

    public function create(array $data): Role
    {
        $role = Role::create(['name' => $data['name']]);
        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }
        return $role->load('permissions');
    }

    public function update(int $id, array $data): ?Role
    {
        $role = $this->findById($id);
        if (!$role) {
            return null;
        }
        $role->update(['name' => $data['name']]);
        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }
        return $role->load('permissions');
    }

    public function delete(int $id): bool
    {
        $role = $this->findById($id);
        return $role ? $role->delete() : false;
    }

    public function syncPermissions(int $id, array $permissions): Role
    {
        $role = $this->findById($id);
        $role->syncPermissions($permissions);
        return $role->load('permissions');
    }
}
