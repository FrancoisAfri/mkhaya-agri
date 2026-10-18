<?php

namespace App\Modules\HR\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Contracts\Actions\GenerateSignatureOtpAction;
use App\Modules\HR\Contracts\Actions\SignDocumentAction;
use App\Modules\HR\Contracts\Http\Requests\SignDocumentRequest;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SignatureController extends Controller
{
    public function view(string $signatureRequestUuid, Request $request): Response
    {
        $sigRequest = SignatureRequest::where('uuid', $signatureRequestUuid)->firstOrFail();

        $this->abortUnlessValidToken($sigRequest, $request->query('token', ''));

        $sigRequest->load(['contract.candidate', 'contract.job']);
        $contract = $sigRequest->contract;

        return Inertia::render('Modules/Contracts/Portal/Sign', [
            'contract' => [
                'uuid'           => $contract->uuid,
                'status'         => $contract->status->value,
                'job_title'      => $contract->job?->title,
                'candidate_name' => $contract->candidate?->full_name,
                'content'        => $contract->content,
                'expires_at'     => $contract->expires_at?->format('d M Y, H:i'),
            ],
            'sigRequest' => [
                'uuid'           => $sigRequest->uuid,
                'signatory_type' => $sigRequest->signatory_type,
            ],
            'token' => $request->query('token', ''),
        ]);
    }

    /**
     * Signatory requests an OTP to proceed with signing.
     * Rate-limited via recruitment.otp-request limiter.
     */
    public function requestOtp(
        string $signatureRequestUuid,
        Request $request,
        GenerateSignatureOtpAction $action,
    ): JsonResponse {
        $sigRequest = SignatureRequest::where('uuid', $signatureRequestUuid)->firstOrFail();

        $this->abortUnlessValidToken($sigRequest, $request->query('token', ''));

        $this->runInTenant($sigRequest, fn () => $action->execute($sigRequest));

        return response()->json(['message' => 'OTP sent to your registered contact.']);
    }

    /**
     * Signatory submits OTP to sign the document.
     * Rate-limited via recruitment.otp-verify limiter.
     */
    public function sign(
        string $signatureRequestUuid,
        SignDocumentRequest $request,
        SignDocumentAction $action,
    ): JsonResponse {
        $sigRequest = SignatureRequest::where('uuid', $signatureRequestUuid)->firstOrFail();

        $this->abortUnlessValidToken($sigRequest, $request->query('token', ''));

        // Signatory is unauthenticated — run in the signature request's tenant so
        // the signed contract + downstream conversion/onboarding land in the right
        // company instead of company_id = NULL.
        $this->runInTenant($sigRequest, fn () => $action->execute($sigRequest, $request->validated('otp'), $request));

        return response()->json(['message' => 'Document signed successfully.']);
    }

    /** Execute a portal action inside the signature request's company tenant. */
    private function runInTenant(SignatureRequest $sigRequest, \Closure $fn): mixed
    {
        $companyId = $sigRequest->company_id ?? $sigRequest->contract?->company_id;

        return app(\App\Modules\Tenancy\Services\TenantContext::class)->run(
            $companyId !== null ? (int) $companyId : null,
            $fn,
        );
    }

    private function abortUnlessValidToken(SignatureRequest $sigRequest, string $token): void
    {
        abort_unless(
            !$sigRequest->isSigned() && $sigRequest->hasValidToken($token),
            403,
            'Invalid, expired, or already-used signing link.',
        );
    }
}
