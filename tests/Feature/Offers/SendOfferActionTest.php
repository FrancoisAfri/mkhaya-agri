<?php

namespace Tests\Feature\Offers;

use App\Modules\HR\Offers\Actions\SendOfferAction;
use App\Modules\HR\Offers\Jobs\SendOfferNotificationJob;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class SendOfferActionTest extends RecruitmentTestCase
{
    private SendOfferAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(SendOfferAction::class);
        Queue::fake();
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_sends_offer_and_sets_token_hash(): void
    {
        $offer = Offer::factory()->create(['status' => OfferStatus::Approved->value]);

        $result = $this->action->execute($offer, $this->hrManager->id);

        $this->assertSame(OfferStatus::Sent, $result->status);
        $this->assertNotNull($result->getRawOriginal('token_hash'));
        $this->assertNotNull($result->token_expires_at);
        $this->assertNotNull($result->sent_at);
    }

    public function test_token_expires_at_is_48_hours_from_now(): void
    {
        $offer = Offer::factory()->create(['status' => OfferStatus::Approved->value]);

        $result = $this->action->execute($offer, $this->hrManager->id);

        $this->assertEqualsWithDelta(
            now()->addHours(48)->timestamp,
            $result->token_expires_at->timestamp,
            5 // allow 5s tolerance
        );
    }

    public function test_dispatches_notification_job_after_send(): void
    {
        $offer = Offer::factory()->create(['status' => OfferStatus::Approved->value]);

        $this->action->execute($offer, $this->hrManager->id);

        Queue::assertPushedOn('notifications', SendOfferNotificationJob::class);
    }

    // ── Status guard ─────────────────────────────────────────────────────────

    public function test_throws_when_offer_is_not_approvable_to_sent(): void
    {
        $offer = Offer::factory()->create(['status' => OfferStatus::Draft->value]);

        $this->expectException(ValidationException::class);

        $this->action->execute($offer, $this->hrManager->id);
    }

    public function test_throws_for_already_accepted_offer(): void
    {
        $offer = Offer::factory()->accepted()->create();

        $this->expectException(ValidationException::class);

        $this->action->execute($offer, $this->hrManager->id);
    }
}
