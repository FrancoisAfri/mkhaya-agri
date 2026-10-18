<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Support\Facades\DB;

class CreateVacancyAction
{
    public function execute(array $data, int $createdBy): Vacancy
    {
        return DB::transaction(function () use ($data, $createdBy) {
            return Vacancy::create([
                ...$data,
                'vacancy_number' => Vacancy::generateVacancyNumber(),
                'status'         => VacancyStatus::Draft->value,
                'created_by'     => $createdBy,
            ]);
        });
    }
}
