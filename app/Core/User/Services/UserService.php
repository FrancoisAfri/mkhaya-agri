<?php

namespace App\Core\User\Services;

use App\Core\User\Models\User;
use App\Core\User\Repositories\UserRepositoryInterface;

class UserService
{
    public function __construct(
        protected UserRepositoryInterface $repository
    ) {}

    public function list()
    {
        return $this->repository->paginate();
    }

    public function create(array $data): User
    {
        return $this->repository->create($data);
    }

    public function update(User $user, array $data): User
    {
        return $this->repository->update($user, $data);
    }
}

