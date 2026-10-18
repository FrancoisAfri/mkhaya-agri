<?php

namespace App\Modules\HR\Offers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Offers\Actions\AcceptOfferAction;
use App\Modules\HR\Offers\Actions\DeclineOfferAction;
use App\Modules\HR\Offers\Actions\GenerateOfferOtpAction;
use App\Modules\HR\Offers\Http\Requests\VerifyOfferOtpRequest;
use App\Modules\HR\Offers\Http\Resources\OfferResource;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CandidateOfferController extends Controller
{
    /**
     * Candidate views the offer — token in query string validates access.
     * No authentication required; the signed token is the credential.
     */
    public function view(string $offerUuid, Request $request): \Inertia\Response
    {
        $offer = Offer::where('uuid', $offerUuid)->firstOrFail();

        $this->abortUnlessValidToken($offer, $request->query('token', ''));

        $offer->load(['candidate', 'job']);

        return Inertia::render('Modules/Offers/Portal/View', [
            'offer' => [
                'uuid'          => $offer->uuid,
                'status'        => $offer->status->value,
                'job_title'     => $offer->job?->title,
                'salary'        => $offer->salary,
                'currency'      => $offer->currency ?? 'ZAR',
                'start_date'    => $offer->start_date?->toDateString(),
                'expires_at'    => $offer->expires_at?->format('d M Y, H:i'),
                'notes'         => $offer->notes,
            ],
            'candidate' => [
                'full_name' => $offer->candidate?->full_name,
                'email'     => $offer->candidate?->email,
            ],
            'token' => $request->query('token', ''),
        ]);
    }

    /**
     * Candidate requests an OTP to proceed with acceptance.
     * Rate-limited to 3 per hour per IP+offerUuid.
     */
    public function requestOtp(
        string $offerUuid,
        Request $request,
        GenerateOfferOtpAction $action,
    ): JsonResponse {
        $offer = Offer::where('uuid', $offerUuid)->firstOrFail();

        $this->abortUnlessValidToken($offer, $request->query('token', ''));

        $this->runInOfferTenant($offer, fn () => $action->execute($offer));

        return response()->json(['message' => 'OTP sent to your registered contact.']);
    }

    /**
     * Candidate accepts the offer by verifying their OTP.
     * Rate-limited to 5 attempts per offer (tracked in otp_attempts column).
     */
    public function accept(
        string $offerUuid,
        VerifyOfferOtpRequest $request,
        AcceptOfferAction $action,
    ): OfferResource {
        $offer = Offer::where('uuid', $offerUuid)->firstOrFail();

        $this->abortUnlessValidToken($offer, $request->query('token', ''));

        // Run in the offer's tenant so the accept + OfferAccepted event (and the
        // queued onboarding it triggers) are scoped/tagged to the hiring company —
        // the candidate is unauthenticated, so no tenant is set otherwise.
        return new OfferResource(
            $this->runInOfferTenant($offer, fn () => $action->execute($offer, $request->validated('otp')))
        );
    }

    /**
     * Candidate declines the offer.
     */
    public function decline(
        string $offerUuid,
        Request $request,
        DeclineOfferAction $action,
    ): OfferResource {
        $offer = Offer::where('uuid', $offerUuid)->firstOrFail();

        $this->abortUnlessValidToken($offer, $request->query('token', ''));

        $request->validate(['reason' => 'nullable|string|max:2000']);

        return new OfferResource(
            $this->runInOfferTenant($offer, fn () => $action->execute($offer, $request->input('reason', '')))
        );
    }

    private function abortUnlessValidToken(Offer $offer, string $token): void
    {
        abort_unless(
            $offer->status === OfferStatus::Sent && $offer->hasValidToken($token),
            403,
            'Invalid or expired offer link.',
        );
    }

    /** Execute a portal action inside the offer's company tenant context. */
    private function runInOfferTenant(Offer $offer, \Closure $fn): mixed
    {
        return app(\App\Modules\Tenancy\Services\TenantContext::class)->run(
            $offer->company_id !== null ? (int) $offer->company_id : null,
            $fn,
        );
    }
}
