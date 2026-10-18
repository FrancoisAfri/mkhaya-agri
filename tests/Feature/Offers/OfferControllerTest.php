<?php

namespace Tests\Feature\Offers;

use App\Modules\HR\Offers\Jobs\SendOfferNotificationJob;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class OfferControllerTest extends RecruitmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_offers_for_a_job(): void
    {
        $job = Job::factory()->create();
        Offer::factory()->count(3)->create(['job_id' => $job->id]);
        Offer::factory()->count(2)->create(); // other jobs

        $this->actingAs($this->hrManager)
            ->get(route('offers.index'))
            ->assertOk()
            // An Inertia page rather than a JSON collection.
            ->assertInertia(fn ($page) => $page->component('Modules/Offers/Index'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_offer_for_application(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.store', [$job, $application]), [
                'position_title' => 'Senior Developer',
                'start_date'     => now()->addMonths(2)->toDateString(),
                'salary_amount'  => 60000,
                'contract_type'  => 'permanent',
            ])
            // Inertia endpoints redirect; the row is what proves the work happened.
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_offers', [
            'application_id' => $application->id,
            'position_title' => 'Senior Developer',
            'status'         => OfferStatus::Draft->value,
        ]);

        $this->assertDatabaseHas('recruitment_offers', [
            'application_id' => $application->id,
            'created_by'     => $this->hrManager->id,
        ]);
    }

    public function test_store_requires_position_title_start_date_and_salary(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.store', [$job, $application]), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position_title', 'start_date', 'salary_amount']);
    }

    public function test_store_returns_404_when_application_belongs_to_different_job(): void
    {
        $job         = Job::factory()->create();
        $otherJob    = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.store', [$job, $application]), [
                'position_title' => 'Developer',
                'start_date'     => now()->addMonths(2)->toDateString(),
                'salary_amount'  => 50000,
            ])
            ->assertNotFound();
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_offer_scoped_to_job(): void
    {
        $job   = Job::factory()->create();
        $offer = Offer::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->get(route('offers.show', [$job, $offer]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Offers/Show')
                ->where('offer.id', $offer->id));
    }

    public function test_show_returns_404_when_offer_belongs_to_different_job(): void
    {
        $job      = Job::factory()->create();
        $otherJob = Job::factory()->create();
        $offer    = Offer::factory()->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->hrManager)
            ->get(route('offers.show', [$job, $offer]))
            ->assertNotFound();
    }

    // ── send ──────────────────────────────────────────────────────────────────

    public function test_send_moves_offer_to_sent_status(): void
    {
        $job   = Job::factory()->create();
        $offer = Offer::factory()->create([
            'job_id' => $job->id,
            'status' => OfferStatus::Approved->value,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.send', [$job, $offer]))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_offers', [
            'id' => $offer->id, 'status' => OfferStatus::Sent->value,
        ]);

        Queue::assertPushedOn('notifications', SendOfferNotificationJob::class);
    }

    // ── withdraw ─────────────────────────────────────────────────────────────

    public function test_withdraw_requires_reason(): void
    {
        $job   = Job::factory()->create();
        $offer = Offer::factory()->sent()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.withdraw', [$job, $offer]), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_withdraw_marks_offer_as_withdrawn(): void
    {
        $job   = Job::factory()->create();
        $offer = Offer::factory()->sent()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.withdraw', [$job, $offer]), [
                'reason' => 'Position no longer available.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_offers', [
            'id' => $offer->id, 'status' => OfferStatus::Withdrawn->value,
        ]);
    }
}
