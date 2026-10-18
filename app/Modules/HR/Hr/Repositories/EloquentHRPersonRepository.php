<?php

namespace App\Modules\HR\Hr\Repositories;

use App\Modules\HR\Hr\Models\HRPerson;


class EloquentHRPersonRepository implements HRPersonRepositoryInterface
{
    public function paginate()
    {
        return HRPerson::with(['jobTitle', 'department', 'manager'])
            ->paginate(15);
    }

    public function find(int $id): ?HRPerson
    {
        return HRPerson::with(['jobTitle', 'department', 'manager'])
            ->find($id);
    }

    public function create(array $data): HRPerson
    {
        return HRPerson::create($data);
    }

    public function update(HRPerson $person, array $data): HRPerson
    {
        $person->update($data);
        return $person;
    }

    public function byDepartment(int $departmentId)
    {
        return HRPerson::where('division_level_4', $departmentId)
            ->where('status', true)
            ->get();
    }

    public function active()
    {
        return HRPerson::where('status', true)->get();
    }
}

