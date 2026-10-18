<?php

namespace Tests\Feature\ESign;

use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Enums\SignerStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\OtpChallenge;
use App\Modules\ESign\Models\Signer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SigningPortalTest extends ESignTestCase
{
    protected const OTP = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeSignerWithToken(): array
    {
        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create(['envelope_id' => $envelope->id]);

        return [$envelope, $signer, $token];
    }

    // ── view ──────────────────────────────────────────────────────────────────

    public function test_view_returns_signing_portal(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->get(route('esign.portal.view', $token))
            ->assertOk();
    }

    public function test_view_marks_signer_as_viewed(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->get(route('esign.portal.view', $token));

        $this->assertSame(SignerStatus::Viewed->value, $signer->fresh()->status->value);
    }

    public function test_view_rejects_invalid_token(): void
    {
        $this->get(route('esign.portal.view', 'invalid-token'))
            ->assertNotFound();
    }

    // ── consent ───────────────────────────────────────────────────────────────

    public function test_consent_records_consent(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->postJson(route('esign.portal.consent', $token))
            ->assertOk();

        $this->assertTrue($signer->fresh()->consent_given);
        $this->assertNotNull($signer->fresh()->consent_given_at);
    }

    // ── OTP ───────────────────────────────────────────────────────────────────

    public function test_otp_creates_challenge_and_queues_mail(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->postJson(route('esign.portal.otp', $token))
            ->assertOk();

        $this->assertDatabaseHas('esign_otp_challenges', ['signer_id' => $signer->id]);
        Mail::assertQueued(\App\Modules\ESign\Mail\SignerOtpMail::class);
    }

    public function test_otp_updates_signer_status_to_otp_sent(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->postJson(route('esign.portal.otp', $token));

        $this->assertSame(SignerStatus::OtpSent->value, $signer->fresh()->status->value);
    }

    // ── sign ──────────────────────────────────────────────────────────────────

    public function test_sign_with_valid_otp_marks_signer_as_signed(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        OtpChallenge::create([
            'signer_id'  => $signer->id,
            'code_hash'  => Hash::make(self::OTP),
            'channel'    => 'email',
            'sent_to'    => $signer->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson(route('esign.portal.sign', $token), [
            'otp'            => self::OTP,
            'signature_data' => 'Alice Smith',
            'signature_type' => 'typed',
        ])->assertOk();

        $signer->refresh();
        $this->assertSame(SignerStatus::Signed->value, $signer->status->value);
        $this->assertNotNull($signer->signed_at);
        $this->assertSame('Alice Smith', $signer->signature_data);
    }

    public function test_sign_completes_envelope_when_all_signers_done(): void
    {
        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create(['envelope_id' => $envelope->id]);

        OtpChallenge::create([
            'signer_id'  => $signer->id,
            'code_hash'  => Hash::make(self::OTP),
            'channel'    => 'email',
            'sent_to'    => $signer->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson(route('esign.portal.sign', $token), [
            'otp'            => self::OTP,
            'signature_data' => 'Test',
            'signature_type' => 'typed',
        ]);

        $this->assertSame(EnvelopeStatus::Completed->value, $envelope->fresh()->status->value);
        $this->assertNotNull($envelope->fresh()->completed_at);
    }

    public function test_sign_fails_with_invalid_otp(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        OtpChallenge::create([
            'signer_id'  => $signer->id,
            'code_hash'  => Hash::make(self::OTP),
            'channel'    => 'email',
            'sent_to'    => $signer->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson(route('esign.portal.sign', $token), [
            'otp'            => '000000',
            'signature_data' => 'Test',
            'signature_type' => 'typed',
        ])->assertUnprocessable();

        $this->assertNotSame(SignerStatus::Signed->value, $signer->fresh()->status->value);
    }

    public function test_sign_logs_audit_entry(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();
        $envelope            = $signer->envelope;

        OtpChallenge::create([
            'signer_id'  => $signer->id,
            'code_hash'  => Hash::make(self::OTP),
            'channel'    => 'email',
            'sent_to'    => $signer->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson(route('esign.portal.sign', $token), [
            'otp'            => self::OTP,
            'signature_data' => 'Test',
            'signature_type' => 'typed',
        ]);

        $this->assertDatabaseHas('esign_audit_entries', [
            'envelope_id' => $envelope->id,
            'signer_id'   => $signer->id,
            'event'       => 'signed',
        ]);
    }

    // ── decline ───────────────────────────────────────────────────────────────

    public function test_decline_marks_signer_as_declined(): void
    {
        [, $signer, $token] = $this->makeSignerWithToken();

        $this->postJson(route('esign.portal.decline', $token))
            ->assertOk();

        $this->assertSame(SignerStatus::Declined->value, $signer->fresh()->status->value);
    }

    // ── expired link ──────────────────────────────────────────────────────────

    public function test_expired_token_returns_403(): void
    {
        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create(['expires_at' => now()->subDay()]);
        Signer::factory()->create([
            'envelope_id'     => $envelope->id,
            'token'           => $token,
            'token_expires_at' => now()->subHour(),
        ]);

        $this->get(route('esign.portal.view', $token))
            ->assertForbidden();
    }
}
