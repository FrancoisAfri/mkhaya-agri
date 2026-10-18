<?php

namespace App\Modules\HR\Recruitment\Actions\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PublishAction
{
    public function __construct(
        private readonly PublishInternallyAction $publishInternally,
        private readonly PublishExternallyAction $publishExternally,
    ) {}

    public function execute(Vacancy $vacancy, int $userId, array $options = []): Vacancy
    {
        return DB::transaction(function () use ($vacancy, $userId, $options) {
            return match ($vacancy->visibility) {
                VacancyVisibility::InternalOnly => $this->publishInternally->execute(
                    $vacancy,
                    $userId,
                ),
                VacancyVisibility::ExternalOnly => $this->publishExternally->execute(
                    $vacancy,
                    $userId,
                ),
                VacancyVisibility::InternalFirstThenExternal => $this->publishInternally->execute(
                    $vacancy,
                    $userId,
                    isset($options['internal_deadline'])
                        ? Carbon::parse($options['internal_deadline'])
                        : null,
                ),
            };
        });
    }
}
