<?php

namespace App\Core\User\Repositories;

use App\Core\User\Models\User;


interface UserRepositoryInterface
{
    public function paginate();
    public function find(int $id): ?User;
    public function create(array $data): User;
    public function update(User $user, array $data): User;
}
