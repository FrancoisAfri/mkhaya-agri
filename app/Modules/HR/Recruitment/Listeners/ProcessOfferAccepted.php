<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Actions\MoveApplicationAction;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessOfferAccepted implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public bool $afterCommit = true;

    public function __construct(private readonly MoveApplicationAction $moveAction) {}

    public function handle(OfferAccepted $event): void
    {
        $offer       = $event->offer;
        $application = $offer->application;

        if (!$application) {
            Log::warning("ProcessOfferAccepted: no application found for offer #{$offer->id}");
            return;
        }

        // Move the application to Hired status to close the recruitment loop
        if ($application->status->canTransitionTo(ApplicationStatus::Hired)) {
            $this->moveAction->execute(
                application: $application,
                newStatus:   ApplicationStatus::Hired,
                userId:      $offer->approved_by ?? $offer->created_by,
            );
        }

        Log::info("Application #{$application->id} moved to Hired after offer #{$offer->id} was accepted.");
    }
}
