<?php

namespace Tests\Feature\Contracts;

use App\Modules\HR\Contracts\Jobs\GenerateContractPdfJob;
use App\Modules\HR\Contracts\Jobs\SendContractNotificationJob;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ContractControllerTest extends RecruitmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_contract_for_accepted_offer(): void
    {
        $offer = Offer::factory()->accepted()->create();

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.contracts.store', [$offer->job_id, $offer]), [
                'content' => '<p>Employment contract content.</p>',
            ])
            // These are Inertia endpoints, not a JSON API: a successful create redirects to the
            // contract it made. The row is the assertion that matters.
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_contracts', [
            'offer_id'   => $offer->id,
            'created_by' => $this->hrManager->id,
            'status'     => ContractStatus::Draft->value,
        ]);

        Queue::assertPushedOn('documents', GenerateContractPdfJob::class);
    }

    public function test_store_returns_422_for_non_accepted_offer(): void
    {
        $offer = Offer::factory()->sent()->create();

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.contracts.store', [$offer->job_id, $offer]), [])
            ->assertUnprocessable();
    }

    public function test_store_returns_422_when_contract_already_exists(): void
    {
        $offer = Offer::factory()->accepted()->create();
        Contract::factory()->create(['offer_id' => $offer->id, 'candidate_id' => $offer->candidate_id]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.contracts.store', [$offer->job_id, $offer]), [])
            ->assertUnprocessable();
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_contract_with_signature_requests(): void
    {
        $offer    = Offer::factory()->accepted()->create();
        $contract = Contract::factory()->create([
            'offer_id'     => $offer->id,
            'candidate_id' => $offer->candidate_id,
        ]);

        $this->actingAs($this->hrManager)
            ->get(route('recruitment.offers.contracts.show', [$offer->job_id, $offer, $contract]))
            ->assertOk()
            // An Inertia page, so the contract arrives as a prop rather than a JSON body.
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Recruitment/Contracts/Show')
                ->where('contract.id', $contract->id)
                ->has('contract.signature_requests'));
    }

    public function test_show_returns_404_when_contract_belongs_to_different_offer(): void
    {
        $offer        = Offer::factory()->accepted()->create();
        $otherOffer   = Offer::factory()->accepted()->create();
        $contract     = Contract::factory()->create(['offer_id' => $otherOffer->id]);

        $this->actingAs($this->hrManager)
            ->get(route('recruitment.offers.contracts.show', [$offer->job_id, $offer, $contract]))
            ->assertNotFound();
    }

    // ── send ──────────────────────────────────────────────────────────────────

    public function test_send_transitions_contract_to_sent_for_signing(): void
    {
        $offer    = Offer::factory()->accepted()->create();
        $contract = Contract::factory()->create([
            'offer_id'     => $offer->id,
            'candidate_id' => $offer->candidate_id,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.contracts.send', [$offer->job_id, $offer, $contract]), [
                'signatories' => [
                    ['type' => 'candidate', 'candidate_id' => $offer->candidate_id, 'user_id' => null],
                    ['type' => 'hr_manager', 'user_id' => $this->hrManager->id, 'candidate_id' => null],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_contracts', [
            'id'     => $contract->id,
            'status' => ContractStatus::SentForSigning->value,
        ]);

        $this->assertDatabaseCount('recruitment_signature_requests', 2);
        Queue::assertPushedOn('notifications', SendContractNotificationJob::class);
    }

    public function test_send_requires_at_least_one_signatory(): void
    {
        $offer    = Offer::factory()->accepted()->create();
        $contract = Contract::factory()->create([
            'offer_id'     => $offer->id,
            'candidate_id' => $offer->candidate_id,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson(route('recruitment.offers.contracts.send', [$offer->job_id, $offer, $contract]), [
                'signatories' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signatories']);
    }
}
