<?php

namespace App\Modules\HR\Hr\Repositories;

use App\Modules\HR\Hr\Models\HRPerson;

interface HRPersonRepositoryInterface
{
    public function paginate();
    public function find(int $id): ?HRPerson;
    public function create(array $data): HRPerson;
    public function update(HRPerson $person, array $data): HRPerson;
    public function byDepartment(int $departmentId);
    public function active();
}

