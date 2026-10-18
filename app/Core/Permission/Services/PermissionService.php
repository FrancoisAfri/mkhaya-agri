<?php

namespace App\Core\Permission\Services;

use App\Core\Permission\Repositories\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    public function __construct(
        protected PermissionRepositoryInterface $permissionRepository
    ) {}

    public function getAll(): Collection
    {
        return $this->permissionRepository->getAll();
    }

    public function create(array $data)
    {
        return $this->permissionRepository->create($data);
    }

    public function find(int $id)
    {
        return $this->permissionRepository->find($id);
    }
    public function update(int $id, array $data): bool
    {
        return $this->permissionRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->permissionRepository->delete($id);
    }
}
