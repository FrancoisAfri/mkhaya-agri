<?php

namespace Tests\Feature\Offers;

use App\Modules\HR\Offers\Actions\AcceptOfferAction;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class AcceptOfferActionTest extends RecruitmentTestCase
{
    private AcceptOfferAction $action;
    private const OTP = '112233';

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(AcceptOfferAction::class);
                /*
         * Scoped to the event under assertion, not everything.
         *
         * A bare Event::fake() replaces the dispatcher, and Eloquent's own `creating` hooks are
         * dispatched through it — so faking every event silently stops models generating their
         * uuid, and the failure surfaces much later as a null uuid or a route missing its
         * parameter. Naming the event keeps model hooks alive.
         */
        Event::fake([OfferAccepted::class]);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_accepts_sent_offer_with_valid_otp(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        $result = $this->action->execute($offer, self::OTP);

        $this->assertSame(OfferStatus::Accepted, $result->status);
        $this->assertNotNull($result->accepted_at);
        $this->assertNull($result->getRawOriginal('otp_hash'));
    }

    public function test_dispatches_offer_accepted_event_on_success(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        $this->action->execute($offer, self::OTP);

        Event::assertDispatched(OfferAccepted::class, fn($e) => $e->offer->id === $offer->id);
    }

    public function test_persists_accepted_status_to_database(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        $this->action->execute($offer, self::OTP);

        $this->assertDatabaseHas('recruitment_offers', [
            'id'     => $offer->id,
            'status' => OfferStatus::Accepted->value,
        ]);
    }

    // ── OTP guard ────────────────────────────────────────────────────────────

    public function test_throws_on_invalid_otp(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        $this->expectException(ValidationException::class);

        $this->action->execute($offer, '000000');
    }

    public function test_increments_otp_attempts_on_failure(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        try {
            $this->action->execute($offer, '000000');
        } catch (ValidationException) {}

        $this->assertSame(1, $offer->fresh()->otp_attempts);
    }

    public function test_locks_offer_after_5_failed_attempts(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->action->execute($offer, '000000');
            } catch (ValidationException) {}
            $offer->refresh();
        }

        $this->assertNotNull($offer->otp_locked_at);
    }

    public function test_throws_when_offer_is_locked(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create([
            'otp_locked_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute($offer, self::OTP);
    }

    // ── Status guard ─────────────────────────────────────────────────────────

    public function test_throws_when_offer_is_not_in_sent_status(): void
    {
        $offer = Offer::factory()->create(); // draft

        $this->expectException(ValidationException::class);

        $this->action->execute($offer, self::OTP);
    }

    // ── Expiry guard ─────────────────────────────────────────────────────────

    public function test_throws_and_expires_offer_when_past_expiry(): void
    {
        $offer = Offer::factory()->sent()->withOtp(self::OTP)->create([
            'expires_at' => now()->subMinute(),
        ]);

        try {
            $this->action->execute($offer, self::OTP);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(OfferStatus::Expired, $offer->fresh()->status);
        }
    }
}
