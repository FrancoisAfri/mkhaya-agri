<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Somebody asked to sign has to be able to find where to do it.
 *
 * Reported as "the system does not allow me to say where the signature goes". It does — the signing
 * page has carried a placer for a while. The problem was that the envelope page never sent anybody
 * there. On a small team the person who sends an envelope is usually also a signer on it, so they
 * follow the notification, land on the sender's view, find Send, View Document and Void, and
 * reasonably conclude that placing a signature is not offered.
 *
 * A missing route between two screens reads exactly like a missing feature.
 */
class SignerCanFindHowToSignTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => $email,
            'password' => bcrypt('x'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    /** An envelope that has gone out, with the given user as one of its signers. */
    private function sentEnvelopeFor(User $user, array $signerOverrides = [])
    {
        Mail::fake();

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Mkhaya Prop & Osizweni SLA', 'document_type' => 'contract'],
            // `kind: internal` with a user id is what links a signer to an account — and the
            // account link is what the signing page matches on, so an email-only signer would be
            // offered a button leading to an empty list.
            signers: [array_merge([
                'name' => 'Francois Keou', 'email' => $user->email, 'role' => 'signer',
                // The account link is what the signing page matches on. Without it a signer would
                // be offered a button leading to an empty list, which is worse than no button.
                'user_id' => $user->id,
            ], $signerOverrides)],
            createdBy: $user->id,
        );

        app(\App\Modules\ESign\Services\EnvelopeDocumentService::class)->store(
            $envelope,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('sla.pdf', '%PDF-1.4 pretend'),
        );

        $this->actingAs($user)->post(route('esign.envelopes.send', $envelope->uuid));

        return $envelope->fresh('signers');
    }

    private function flag($user, $envelope): ?bool
    {
        return $this->actingAs($user)
            ->get(route('esign.envelopes.show', $envelope->uuid))
            ->viewData('page')['props']['awaitingMySignature'] ?? null;
    }

    // ── The missing route ────────────────────────────────────────────────────

    public function test_a_signer_is_told_the_envelope_is_waiting_on_them(): void
    {
        $user = $this->user('signer' . Str::random(5) . '@example.test');
        $envelope = $this->sentEnvelopeFor($user);

        $this->assertTrue($this->flag($user, $envelope),
            'A signer landing on the envelope has no way to reach the signing page.');
    }

    /** Somebody who is not a signer must not be offered it. */
    public function test_a_non_signer_is_not_offered_it(): void
    {
        $owner = $this->user('owner' . Str::random(5) . '@example.test');
        $other = $this->user('other' . Str::random(5) . '@example.test');

        // The envelope is waiting on the owner, not on this reader.
        $envelope = $this->sentEnvelopeFor($owner);

        $this->assertFalse($this->flag($other, $envelope));
    }

    /** Once signed there is nothing left to do, and the button would lead to an empty list. */
    public function test_it_disappears_once_they_have_signed(): void
    {
        $user = $this->user('done' . Str::random(5) . '@example.test');
        $envelope = $this->sentEnvelopeFor($user);

        Signer::where('envelope_id', $envelope->id)
            ->update(['signed_at' => now(), 'status' => 'signed']);

        $this->assertFalse($this->flag($user, $envelope->fresh('signers')));
    }

    /** A draft has not been sent, so nobody has been asked for anything yet. */
    public function test_a_draft_asks_nobody_to_sign(): void
    {
        Mail::fake();
        $user = $this->user('draft' . Str::random(5) . '@example.test');

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Not sent yet', 'document_type' => 'contract'],
            signers: [['name' => 'Francois Keou', 'email' => $user->email, 'role' => 'signer', 'user_id' => $user->id]],
            createdBy: $user->id,
        );

        $this->assertFalse($this->flag($user, $envelope));
    }

    // ── What goes under the mark ─────────────────────────────────────────────

    /**
     * The signing page offers a name to print under the signature, defaulted from the HR record.
     *
     * A drawn squiggle says nothing about who made it, and a company is bound by somebody signing
     * in a stated capacity — so both belong on the page, not only in the audit trail where a reader
     * of the PDF never sees them.
     */
    public function test_the_signing_page_offers_a_name_to_print(): void
    {
        $user = $this->user('named' . Str::random(5) . '@example.test');
        $this->sentEnvelopeFor($user);

        $props = $this->actingAs($user)
            ->get(route('esign.envelopes.mine'))
            ->viewData('page')['props'];

        $this->assertArrayHasKey('signerName', $props,
            'The signing page cannot offer a name to print under the signature.');
    }
}
