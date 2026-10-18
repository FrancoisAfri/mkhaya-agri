<?php


namespace App\Core\Role\Repositories;

use App\Core\Role\Models\Role;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface
{
    public function getAll(): Collection;

    public function findById(int $id): ?Role;

    public function create(array $data): Role;

    public function update(int $id, array $data): ?Role;

    public function delete(int $id): bool;

    public function syncPermissions(int $id, array $permissions): Role;
}
