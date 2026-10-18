<?php

namespace Tests\Feature\Contracts;

use App\Modules\HR\Contracts\Actions\SignDocumentAction;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\HR\Recruitment\Events\CandidateConverted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class SignDocumentActionTest extends RecruitmentTestCase
{
    private SignDocumentAction $action;
    private const OTP = '445566';

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(SignDocumentAction::class);
                /*
         * Scoped to the event under assertion, not everything.
         *
         * A bare Event::fake() replaces the dispatcher, and Eloquent's own `creating` hooks are
         * dispatched through it — so faking every event silently stops models generating their
         * uuid, and the failure surfaces much later as a null uuid or a route missing its
         * parameter. Naming the event keeps model hooks alive.
         */
        Event::fake([CandidateConverted::class]);
    }

    private function makeRequest(): Request
    {
        $request = Request::create('/sign', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        return $request;
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_signs_document_with_valid_otp(): void
    {
        $contract = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id]);

        $result = $this->action->execute($sigRequest, self::OTP, $this->makeRequest());

        $this->assertNotNull($result->signed_at);
        $this->assertNull($result->getRawOriginal('otp_hash'));
        $this->assertSame('127.0.0.1', $result->signature_ip);
    }

    public function test_marks_contract_fully_signed_when_all_signatories_have_signed(): void
    {
        $contract   = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id]);

        $this->action->execute($sigRequest, self::OTP, $this->makeRequest());

        $this->assertSame(ContractStatus::Signed, $contract->fresh()->status);
        $this->assertNotNull($contract->fresh()->fully_signed_at);
    }

    public function test_dispatches_candidate_converted_when_fully_signed(): void
    {
        $contract   = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id]);

        $this->action->execute($sigRequest, self::OTP, $this->makeRequest());

        Event::assertDispatched(CandidateConverted::class,
            fn($e) => $e->contract->id === $contract->id
        );
    }

    public function test_does_not_mark_fully_signed_when_other_signatures_pending(): void
    {
        $contract = Contract::factory()->sentForSigning()->create();

        // First signatory — gets OTP and signs
        $first = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id, 'signatory_type' => 'candidate']);

        // Second signatory — not yet signed
        SignatureRequest::factory()->create([
            'contract_id'    => $contract->id,
            'signatory_type' => 'hr_manager',
            'user_id'        => $this->hrManager->id,
        ]);

        $this->action->execute($first, self::OTP, $this->makeRequest());

        $this->assertNotSame(ContractStatus::Signed, $contract->fresh()->status);
        Event::assertNotDispatched(CandidateConverted::class);
    }

    // ── OTP guard ────────────────────────────────────────────────────────────

    public function test_throws_on_invalid_otp(): void
    {
        $contract   = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($sigRequest, '000000', $this->makeRequest());
    }

    public function test_locks_signature_request_after_5_failed_attempts(): void
    {
        $contract   = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->withOtp(self::OTP)
            ->create(['contract_id' => $contract->id]);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->action->execute($sigRequest, '000000', $this->makeRequest());
            } catch (ValidationException) {}
            $sigRequest->refresh();
        }

        $this->assertNotNull($sigRequest->otp_locked_at);
    }

    // ── Already-signed guard ──────────────────────────────────────────────────

    public function test_throws_when_already_signed(): void
    {
        $contract   = Contract::factory()->sentForSigning()->create();
        $sigRequest = SignatureRequest::factory()
            ->signed()
            ->create(['contract_id' => $contract->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($sigRequest, self::OTP, $this->makeRequest());
    }
}
