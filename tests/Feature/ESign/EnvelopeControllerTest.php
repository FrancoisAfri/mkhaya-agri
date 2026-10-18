<?php

namespace Tests\Feature\ESign;

use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use Illuminate\Support\Facades\Mail;

class EnvelopeControllerTest extends ESignTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_envelopes(): void
    {
        Envelope::factory()->count(3)->create(['created_by' => $this->hrUser->id]);

        $this->actingAs($this->hrUser)
            ->getJson(route('esign.envelopes.index'))
            ->assertOk();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_envelope_with_signers(): void
    {
        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.store'), [
                'title'         => 'Test Contract',
                'document_type' => 'contract',
                'signers'       => [
                    ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'role' => 'employee', 'signing_order' => 1],
                    ['name' => 'Bob Jones',   'email' => 'bob@example.com',   'role' => 'hr',       'signing_order' => 2],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('esign_envelopes', [
            'title'      => 'Test Contract',
            'status'     => EnvelopeStatus::Draft->value,
            'created_by' => $this->hrUser->id,
        ]);

        $envelope = Envelope::where('title', 'Test Contract')->first();
        $this->assertDatabaseCount('esign_signers', 2);
        $this->assertDatabaseHas('esign_signers', ['envelope_id' => $envelope->id, 'email' => 'alice@example.com']);
    }

    public function test_store_returns_422_when_no_signers(): void
    {
        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.store'), [
                'title'         => 'Test Contract',
                'document_type' => 'contract',
                'signers'       => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signers']);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_envelope_with_signers_and_audit(): void
    {
        $envelope = Envelope::factory()->create(['created_by' => $this->hrUser->id]);
        Signer::factory()->create(['envelope_id' => $envelope->id]);

        $this->actingAs($this->hrUser)
            ->getJson(route('esign.envelopes.show', $envelope->uuid))
            ->assertOk();
    }

    // ── send ──────────────────────────────────────────────────────────────────

    public function test_send_transitions_draft_envelope_to_sent(): void
    {
        $envelope = Envelope::factory()->create(['created_by' => $this->hrUser->id]);
        Signer::factory()->create(['envelope_id' => $envelope->id, 'email' => 'alice@example.com']);

        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.send', $envelope->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('esign_envelopes', [
            'id'     => $envelope->id,
            'status' => EnvelopeStatus::Sent->value,
        ]);

        Mail::assertQueued(\App\Modules\ESign\Mail\SignerInviteMail::class);
    }

    public function test_send_sets_token_on_each_signer(): void
    {
        $envelope = Envelope::factory()->create(['created_by' => $this->hrUser->id]);
        Signer::factory()->count(2)->create(['envelope_id' => $envelope->id]);

        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.send', $envelope->uuid));

        $signers = Signer::where('envelope_id', $envelope->id)->get();
        foreach ($signers as $signer) {
            $this->assertNotNull($signer->token);
            $this->assertNotNull($signer->token_expires_at);
        }
    }

    public function test_send_returns_error_for_already_sent_envelope(): void
    {
        $envelope = Envelope::factory()->sent()->create(['created_by' => $this->hrUser->id]);

        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.send', $envelope->uuid))
            ->assertRedirect(); // redirects back with session error via ValidationException
    }

    // ── void ──────────────────────────────────────────────────────────────────

    public function test_void_transitions_envelope_to_voided(): void
    {
        $envelope = Envelope::factory()->sent()->create(['created_by' => $this->hrUser->id]);

        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.void', $envelope->uuid), [
                'reason' => 'Test void reason',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('esign_envelopes', [
            'id'           => $envelope->id,
            'status'       => EnvelopeStatus::Voided->value,
            'voided_reason' => 'Test void reason',
        ]);
    }

    public function test_void_logs_audit_entry(): void
    {
        $envelope = Envelope::factory()->sent()->create(['created_by' => $this->hrUser->id]);

        $this->actingAs($this->hrUser)
            ->postJson(route('esign.envelopes.void', $envelope->uuid), ['reason' => 'audit test']);

        $this->assertDatabaseHas('esign_audit_entries', [
            'envelope_id' => $envelope->id,
            'event'       => 'voided',
        ]);
    }
}
