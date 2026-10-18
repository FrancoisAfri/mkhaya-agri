<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Validation\ValidationException;

class CloseVacancyAction
{
    public function execute(Vacancy $vacancy, int $userId, string $reason = 'filled'): Vacancy
    {
        if ($vacancy->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => 'This vacancy is already closed.',
            ]);
        }

        $newStatus = $reason === 'filled'
            ? VacancyStatus::Filled
            : VacancyStatus::Closed;

        $vacancy->update([
            'status'    => $newStatus->value,
            'closed_at' => now(),
        ]);

        return $vacancy->refresh();
    }
}
