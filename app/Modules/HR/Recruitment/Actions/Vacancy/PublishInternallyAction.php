<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Jobs\SendInternalJobNotificationsJob;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PublishInternallyAction
{
    public function execute(Vacancy $vacancy, int $userId, ?Carbon $internalDeadline = null): Vacancy
    {
        $allowedStatuses = [VacancyStatus::Draft, VacancyStatus::OnHold];

        if (!in_array($vacancy->status, $allowedStatuses)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or on-hold vacancies can be published internally.',
            ]);
        }

        $updates = [
            'status'               => VacancyStatus::InternalOnly->value,
            'published_internally_at' => now(),
        ];

        if ($vacancy->visibility === VacancyVisibility::InternalFirstThenExternal && $internalDeadline) {
            $updates['internal_deadline'] = $internalDeadline;
        }

        $vacancy->update($updates);

        SendInternalJobNotificationsJob::dispatch($vacancy);

        return $vacancy->refresh();
    }
}
