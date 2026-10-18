<?php

namespace App\Modules\HR\Hr\Services;

use App\Modules\HR\Models\HRPerson;
use App\Modules\HR\Hr\Repositories\HRPersonRepositoryInterface;

class HRPersonService
{
    public function __construct(
        protected HRPersonRepositoryInterface $repository
    ) {}

    public function list()
    {
        return $this->repository->paginate();
    }

    public function create(array $data): HRPerson
    {
        return $this->repository->create($data);
    }

    public function update(HRPerson $person, array $data): HRPerson
    {
        return $this->repository->update($person, $data);
    }

    public function getActiveEmployees()
    {
        return $this->repository->active();
    }
}
