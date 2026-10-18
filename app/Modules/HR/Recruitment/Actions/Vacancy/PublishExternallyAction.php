<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Validation\ValidationException;

class PublishExternallyAction
{
    public function execute(Vacancy $vacancy, int $userId): Vacancy
    {
        $allowedStatuses = [
            VacancyStatus::Draft,
            VacancyStatus::OnHold,
            VacancyStatus::InternalOnly,
        ];

        if (!in_array($vacancy->status, $allowedStatuses)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft, on-hold, or internally published vacancies can be published externally.',
            ]);
        }

        // Ensure application token exists for the public URL
        $updates = [
            'status'               => VacancyStatus::External->value,
            'published_externally_at' => now(),
        ];

        if (!$vacancy->application_token) {
            $updates['application_token'] = bin2hex(random_bytes(32));
        }

        $vacancy->update($updates);

        return $vacancy->refresh();
    }
}
