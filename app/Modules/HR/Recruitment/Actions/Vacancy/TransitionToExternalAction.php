<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Validation\ValidationException;

class TransitionToExternalAction
{
    public function execute(Vacancy $vacancy): Vacancy
    {
        if ($vacancy->status !== VacancyStatus::InternalOnly) {
            throw ValidationException::withMessages([
                'status' => 'Only internally published vacancies can be transitioned to external.',
            ]);
        }

        if ($vacancy->visibility !== VacancyVisibility::InternalFirstThenExternal) {
            throw ValidationException::withMessages([
                'visibility' => 'Only vacancies with "Internal First, Then External" visibility can be transitioned.',
            ]);
        }

        $vacancy->update([
            'status'                   => VacancyStatus::External->value,
            'transitioned_to_external_at' => now(),
            'published_externally_at'  => now(),
        ]);

        return $vacancy->refresh();
    }
}
