<?php

namespace App\Core\Permission\Repositories;

use Spatie\Permission\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class EloquentPermissionRepository implements PermissionRepositoryInterface
{

    public function getAll(): Collection
    {
        return Permission::all();
    }

    public function create(array $data): Permission
    {
        return Permission::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);
    }

    public function find(int $id): ?Permission
    {
        return Permission::find($id);
    }

    public function update(int $id, array $data): bool
    {
        $permission = Permission::findOrFail($id);
        return $permission->update([
            'name' => $data['name'] ?? $permission->name,
            'guard_name' => $data['guard_name'] ?? $permission->guard_name,
        ]);
    }


    public function delete(int $id): bool
    {
        $permission = Permission::findOrFail($id);
        return $permission->delete();
    }
}
