<?php


namespace App\Core\Permission\Repositories;

use Spatie\Permission\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

interface PermissionRepositoryInterface
{
    public function getAll(): Collection;
    public function create(array $data): Permission;
    public function find(int $id): ?Permission;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;

}
