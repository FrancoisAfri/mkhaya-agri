<?php

namespace Tests\Feature\ESign;

use App\Core\SMS\Services\SmsService;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SmsOtpTest extends ESignTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_otp_falls_back_to_email_when_sms_not_configured(): void
    {
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldNotReceive('send');
        });

        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create([
            'envelope_id' => $envelope->id,
            'phone'       => '+27834231615',
        ]);

        $this->postJson(route('esign.portal.otp', $token))->assertOk();

        $this->assertDatabaseHas('esign_otp_challenges', [
            'signer_id' => $signer->id,
            'channel'   => 'email',
            'sent_to'   => $signer->email,
        ]);

        Mail::assertQueued(\App\Modules\ESign\Mail\SignerOtpMail::class);
    }

    /** The active SMS configuration `SmsService::isConfigured()` looks for. */
    private function activeSmsConfiguration(): void
    {
        \App\Core\SMS\Models\SmsConfiguration::query()->update(['is_active' => false]);

        \App\Core\SMS\Models\SmsConfiguration::create([
            'provider'     => 'bulksms',
            'sms_username' => 'test-user',
            'sms_password' => 'test-pass',
            'is_active'    => true,
        ]);
    }

    public function test_otp_uses_sms_channel_when_phone_and_sms_configured(): void
    {
        /*
         * A real configuration row, because the decision is made through a static call.
         *
         * ESignService asks `SmsService::isConfigured()`, which reads the active
         * SmsConfiguration — so binding a mock instance into the container changes nothing, and
         * the OTP quietly went out by email while the test asserted it had gone by SMS. The
         * outbound send is faked at the HTTP layer instead, which is where it actually leaves.
         */
        $this->activeSmsConfiguration();
        Http::fake(['*' => Http::response('OK', 200)]);

        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create([
            'envelope_id' => $envelope->id,
            'phone'       => '+27834231615',
        ]);

        $this->postJson(route('esign.portal.otp', $token))->assertOk();

        $this->assertDatabaseHas('esign_otp_challenges', [
            'signer_id' => $signer->id,
            'channel'   => 'sms',
            'sent_to'   => '+27834231615',
        ]);

        // Email still queued as backup
        Mail::assertQueued(\App\Modules\ESign\Mail\SignerOtpMail::class);
    }

    public function test_otp_uses_email_when_no_phone_even_if_sms_configured(): void
    {
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('send');
        });

        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create([
            'envelope_id' => $envelope->id,
            'phone'       => null,
        ]);

        $this->postJson(route('esign.portal.otp', $token))->assertOk();

        $this->assertDatabaseHas('esign_otp_challenges', [
            'signer_id' => $signer->id,
            'channel'   => 'email',
        ]);
    }

    public function test_audit_entry_records_sms_channel_and_delivery_status(): void
    {
        $this->activeSmsConfiguration();
        Http::fake(['*' => Http::response('OK', 200)]);

        $token    = Str::random(64);
        $envelope = Envelope::factory()->sent()->create();
        $signer   = Signer::factory()->withToken($token)->create([
            'envelope_id' => $envelope->id,
            'phone'       => '+27834231615',
        ]);

        $this->postJson(route('esign.portal.otp', $token));

        $entry = \App\Modules\ESign\Models\AuditEntry::where('event', 'otp_sent')
            ->where('signer_id', $signer->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals('sms', $entry->metadata['channel']);
        $this->assertTrue($entry->metadata['sms_sent']);
    }
}
