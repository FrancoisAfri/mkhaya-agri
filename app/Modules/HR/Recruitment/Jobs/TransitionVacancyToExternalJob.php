<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\Actions\Vacancy\TransitionToExternalAction;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransitionVacancyToExternalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(TransitionToExternalAction $action): void
    {
        // Scheduled with no tenant context — sweep each company inside its own
        // tenant so the transition's side effects (publish records, tokens,
        // notifications) are scoped/tagged to the right company.
        $transitioned = 0;

        app(\App\Modules\Tenancy\Services\TenantContext::class)->eachCompany(function () use ($action, &$transitioned) {
            Vacancy::where('visibility', VacancyVisibility::InternalFirstThenExternal->value)
                ->where('status', VacancyStatus::InternalOnly->value)
                ->whereDate('internal_deadline', '<=', now()->toDateString())
                ->each(function (Vacancy $vacancy) use ($action, &$transitioned) {
                    $action->execute($vacancy);
                    $transitioned++;
                });
        });

        Log::info("TransitionVacancyToExternalJob: transitioned {$transitioned} vacancies to external.");
    }
}
