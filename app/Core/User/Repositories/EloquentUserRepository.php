<?php

namespace App\Core\User\Repositories;

use App\Core\User\Models\User;
use App\Core\User\Repositories\UserRepositoryInterface;



class EloquentUserRepository implements UserRepositoryInterface
{
    public function paginate()
    {
        return User::query()->paginate(15);
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user;
    }
}

