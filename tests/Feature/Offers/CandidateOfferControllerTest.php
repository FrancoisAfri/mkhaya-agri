<?php

namespace Tests\Feature\Offers;

use App\Modules\HR\Offers\Jobs\SendOfferOtpJob;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class CandidateOfferControllerTest extends RecruitmentTestCase
{
    private const OTP = '998877';
    private string $rawToken;
    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
                /*
         * Scoped to the event under assertion, not everything.
         *
         * A bare Event::fake() replaces the dispatcher, and Eloquent's own `creating` hooks are
         * dispatched through it — so faking every event silently stops models generating their
         * uuid, and the failure surfaces much later as a null uuid or a route missing its
         * parameter. Naming the event keeps model hooks alive.
         */
        Event::fake([OfferAccepted::class]);

        $this->rawToken = Str::random(64);

        $this->offer = Offer::factory()->create([
            'status'           => OfferStatus::Sent->value,
            'token_hash'       => hash('sha256', $this->rawToken),
            'token_expires_at' => now()->addHours(48),
            'sent_at'          => now(),
            'expires_at'       => now()->addHours(48),
        ]);
    }

    // ── view ──────────────────────────────────────────────────────────────────

    public function test_candidate_can_view_offer_with_valid_token(): void
    {
        $this->get(route('recruitment.offers.portal.view', [
                'offerUuid' => $this->offer->uuid,
                'token'     => $this->rawToken,
            ]))
            ->assertOk()
            // An Inertia page, not a JSON document.
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Offers/Portal/View')
                ->where('offer.uuid', $this->offer->uuid));
    }

    public function test_view_returns_403_with_invalid_token(): void
    {
        $this->getJson(route('recruitment.offers.portal.view', [
                'offerUuid' => $this->offer->uuid,
                'token'     => 'invalid-token',
            ]))
            ->assertForbidden();
    }

    public function test_view_returns_403_for_non_sent_offer(): void
    {
        $offer = Offer::factory()->create(['status' => OfferStatus::Draft->value]);

        $this->getJson(route('recruitment.offers.portal.view', [
                'offerUuid' => $offer->uuid,
                'token'     => $this->rawToken,
            ]))
            ->assertForbidden();
    }

    // ── requestOtp ───────────────────────────────────────────────────────────

    public function test_request_otp_generates_and_queues_otp(): void
    {
        $this->postJson(route('recruitment.offers.portal.otp', [
                'offerUuid' => $this->offer->uuid,
                'token'     => $this->rawToken,
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'OTP sent to your registered contact.');

        Queue::assertPushedOn('notifications', SendOfferOtpJob::class);
        $this->assertNotNull($this->offer->fresh()->otp_hash);
    }

    // ── accept ────────────────────────────────────────────────────────────────

    public function test_candidate_accepts_offer_with_valid_otp(): void
    {
        $this->offer->otp_hash       = hash('sha256', self::OTP);
        $this->offer->otp_expires_at = now()->addMinutes(15);
        $this->offer->save();

        $this->postJson(route('recruitment.offers.portal.accept', [
                'offerUuid' => $this->offer->uuid,
                'token'     => $this->rawToken,
            ]), ['otp' => self::OTP])
            ->assertOk()
            ->assertJsonPath('data.status.value', OfferStatus::Accepted->value);

        Event::assertDispatched(OfferAccepted::class);
    }

    public function test_accept_returns_422_for_wrong_otp(): void
    {
        $this->offer->otp_hash       = hash('sha256', self::OTP);
        $this->offer->otp_expires_at = now()->addMinutes(15);
        $this->offer->save();

        $this->postJson(route('recruitment.offers.portal.accept', [
                'offerUuid' => $this->offer->uuid,
                'token'     => $this->rawToken,
            ]), ['otp' => '000000'])
            ->assertUnprocessable();
    }

    // ── decline ───────────────────────────────────────────────────────────────

    public function test_candidate_declines_offer(): void
    {
        $this->postJson(route('recruitment.offers.portal.decline', [
                'offerUuid' => $this->offer->uuid,
                'token'     => $this->rawToken,
            ]), ['reason' => 'I received a better offer.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', OfferStatus::Declined->value);
    }
}
