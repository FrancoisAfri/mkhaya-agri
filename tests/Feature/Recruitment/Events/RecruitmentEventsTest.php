<?php

namespace Tests\Feature\Recruitment\Events;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Events\CandidateApplied;
use App\Modules\HR\Recruitment\Events\CandidateConverted;
use App\Modules\HR\Recruitment\Events\InterviewScheduled;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use App\Modules\HR\Recruitment\Events\RequisitionApproved;
use App\Modules\HR\Recruitment\Events\ShortlistGenerated;
use App\Modules\HR\Recruitment\Listeners\NotifyHrOnRequisitionApproval;
use App\Modules\HR\Recruitment\Listeners\NotifyHrOnShortlistGenerated;
use App\Modules\HR\Recruitment\Listeners\ProcessOfferAccepted;
use App\Modules\HR\Recruitment\Listeners\SendApplicationConfirmation;
use App\Modules\HR\Recruitment\Listeners\SendInterviewScheduledNotification;
use App\Modules\HR\Recruitment\Listeners\TriggerOnboarding;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class RecruitmentEventsTest extends RecruitmentTestCase
{
    // NOTE: Models must be created BEFORE Event::fake() — several recruitment
    // models generate their `uuid` in a `creating` model event, which Event::fake()
    // would otherwise suppress. Faking after creation still lets assertListening /
    // assertDispatched work (listeners are registered at boot).

    // ── Event → Listener registration ────────────────────────────────────────

    public function test_candidate_applied_is_listened_to_by_send_application_confirmation(): void
    {
        $application = Application::factory()->create();
        Event::fake();

        CandidateApplied::dispatch($application);

        Event::assertListening(CandidateApplied::class, SendApplicationConfirmation::class);
    }

    public function test_requisition_approved_is_listened_to_by_notify_hr(): void
    {
        $requisition = Requisition::factory()->pending()->create();
        Event::fake();

        RequisitionApproved::dispatch($requisition);

        Event::assertListening(RequisitionApproved::class, NotifyHrOnRequisitionApproval::class);
    }

    public function test_shortlist_generated_is_listened_to_by_notify_hr(): void
    {
        $shortlist = Shortlist::factory()->create();
        Event::fake();

        ShortlistGenerated::dispatch($shortlist);

        Event::assertListening(ShortlistGenerated::class, NotifyHrOnShortlistGenerated::class);
    }

    public function test_interview_scheduled_is_listened_to_by_send_interview_invite(): void
    {
        $interview = Interview::factory()->create();
        Event::fake();

        InterviewScheduled::dispatch($interview);

        Event::assertListening(InterviewScheduled::class, SendInterviewScheduledNotification::class);
    }

    public function test_offer_accepted_is_listened_to_by_process_offer_accepted(): void
    {
        $offer = Offer::factory()->accepted()->create();
        Event::fake();

        OfferAccepted::dispatch($offer);

        Event::assertListening(OfferAccepted::class, ProcessOfferAccepted::class);
    }

    public function test_candidate_converted_is_listened_to_by_trigger_onboarding(): void
    {
        $contract = Contract::factory()->signed()->create();
        Event::fake();

        CandidateConverted::dispatch($contract);

        Event::assertListening(CandidateConverted::class, TriggerOnboarding::class);
    }

    // ── Event dispatch ────────────────────────────────────────────────────────

    public function test_events_carry_correct_model_instances(): void
    {
        $application = Application::factory()->create();
        Event::fake();

        CandidateApplied::dispatch($application);

        Event::assertDispatched(CandidateApplied::class, fn($e) =>
            $e->application->id === $application->id
        );
    }

    public function test_offer_accepted_event_carries_offer(): void
    {
        $offer = Offer::factory()->accepted()->create();
        Event::fake();

        OfferAccepted::dispatch($offer);

        Event::assertDispatched(OfferAccepted::class, fn($e) =>
            $e->offer->id === $offer->id
        );
    }

    public function test_candidate_converted_event_carries_contract(): void
    {
        $contract = Contract::factory()->signed()->create();
        Event::fake();

        CandidateConverted::dispatch($contract);

        Event::assertDispatched(CandidateConverted::class, fn($e) =>
            $e->contract->id === $contract->id
        );
    }
}
