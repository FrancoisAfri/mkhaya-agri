<?php

namespace App\Core\Role\Services;

use App\Core\Role\Repositories\RoleRepositoryInterface;
use App\Core\Role\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoleService
{
    public function __construct(
        protected RoleRepositoryInterface $roleRepository
    ) {}

    public function getAll(): Collection
    {
        return $this->roleRepository->getAll();
    }

    public function findById(int $id): Role
    {
        $role = $this->roleRepository->findById($id);
        if (!$role) {
            throw new NotFoundHttpException('Role not found');
        }
        return $role;
    }

    public function create(array $data): Role
    {
        return $this->roleRepository->create($data);
    }

    public function update(int $id, array $data): Role
    {
        $role = $this->roleRepository->update($id, $data);
        if (!$role) {
            throw new NotFoundHttpException('Role not found');
        }
        return $role;
    }

    public function delete(int $id): void
    {
        $deleted = $this->roleRepository->delete($id);
        if (!$deleted) {
            throw new NotFoundHttpException('Role not found');
        }
    }

    public function syncPermissions(int $id, array $permissions): Role
    {
        return $this->roleRepository->syncPermissions($id, $permissions);
    }
}
