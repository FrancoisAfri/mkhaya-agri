<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The document an envelope is about, from upload to executed copy.
 *
 * The module shipped without any of this: an envelope carried a title, a type and signers, and the
 * signing portal offered consent, an OTP and a signature box with nothing to read. The schema had
 * document_path from the start; nothing ever wrote to it.
 */
class EnvelopeDocumentTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'u' . Str::random(8) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    /** A minimal but genuine PDF — the magic number is what the upload and the stamp both check. */
    private function pdf(string $marker = 'Hello'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF {$marker}";

        return UploadedFile::fake()->createWithContent('agreement.pdf', $body);
    }

    private function envelopeWithDocument(User $user): Envelope
    {
        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Service agreement', 'document_type' => 'contract'],
            signers: [['name' => 'Sipho Khumalo', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        return app(EnvelopeDocumentService::class)->store($envelope, $this->pdf());
    }

    public function test_a_document_can_be_uploaded_when_the_envelope_is_created(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Service agreement',
            'document_type' => 'contract',
            'signers'       => [['name' => 'Sipho Khumalo', 'email' => 'sipho@example.test', 'role' => 'signer']],
        ])->assertRedirect();

        $envelope = Envelope::where('title', 'Service agreement')->latest('id')->firstOrFail();

        $this->assertNotNull($envelope->document_path);
        // The hash is what a recipient checks the executed copy against, so it is recorded up front.
        $this->assertSame(64, strlen((string) $envelope->document_hash));
    }

    /**
     * The gap the first cut left: the upload lived only on the create form, so a draft that had
     * been made without one had nowhere to get it. The screen said "upload a document before
     * sending" and offered no way to do that.
     */
    public function test_a_document_can_be_attached_to_an_existing_draft(): void
    {
        $user = $this->user();

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Started early', 'document_type' => 'other'],
            signers: [['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        $this->assertNull($envelope->document_path);

        $this->actingAs($user)
            ->post(route('esign.envelopes.document.upload', $envelope->uuid), ['document' => $this->pdf()])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($envelope->fresh()->document_path);

        // And now it can go out.
        \Illuminate\Support\Facades\Mail::fake();
        $this->actingAs($user)->post(route('esign.envelopes.send', $envelope->uuid));
        $this->assertSame('sent', $envelope->fresh()->status->value);
    }

    public function test_the_document_cannot_be_swapped_once_the_envelope_has_gone_out(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);

        $original = $envelope->fresh()->document_hash;

        // People have been asked to sign a particular document. Replacing it underneath them is
        // not an edit.
        $this->actingAs($user)
            ->post(route('esign.envelopes.document.upload', $envelope->uuid), ['document' => $this->pdf('different')])
            ->assertStatus(422);

        $this->assertSame($original, $envelope->fresh()->document_hash);
    }

    /**
     * The sidebar's "Sign" item is /esign/envelopes-hub. This group registers before the hub
     * routes, so a bare {uuid} swallowed it: the literal reached EnvelopeController@show as the
     * string "envelopes-hub", Postgres refused it as a uuid, and a nav link 500ed.
     */
    public function test_the_hub_link_is_not_swallowed_by_the_envelope_wildcard(): void
    {
        $response = $this->actingAs($this->user())->get('/esign/envelopes-hub');

        $this->assertNotSame(500, $response->status(), 'esign/{uuid} is matching the hub path again.');
    }

    public function test_a_uuid_shaped_path_still_reaches_the_envelope(): void
    {
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);

        $this->actingAs($user)
            ->get(route('esign.envelopes.show', $envelope->uuid))
            ->assertOk();
    }

    public function test_an_envelope_with_no_document_cannot_be_sent(): void
    {
        $user = $this->user();

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Empty', 'document_type' => 'other'],
            signers: [['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        $this->actingAs($user)
            ->post(route('esign.envelopes.send', $envelope->uuid))
            ->assertSessionHas('error');

        // Still a draft: a signer who opens an empty envelope will not sign and will not say why.
        $this->assertSame('draft', $envelope->fresh()->status->value);
    }

    public function test_the_signer_can_fetch_the_document_with_their_token(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);

        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);
        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();

        $res = $this->get(route('esign.portal.document', ['token' => $signer->token]));

        $this->assertGuest();
        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $res->streamedContent());
    }

    /**
     * What a signer sees when they refresh, or follow the link from their email a second time.
     *
     * The guard refused any token that had been used, so the page could never reach the "you have
     * signed this" state it has always had. Someone who did exactly what was asked was told their
     * link was "invalid, expired, or already-used" — which reads as though the signature failed.
     */
    public function test_a_signer_who_has_already_signed_still_sees_their_page(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();
        $signer->forceFill([
            'status'    => \App\Modules\ESign\Enums\SignerStatus::Signed->value,
            'signed_at' => now(),
        ])->save();

        $this->get(route('esign.portal.view', ['token' => $signer->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/ESign/Portal/Sign')
                ->where('signer.is_signed', true));

        // And they can still read what they put their name to.
        $this->get(route('esign.portal.document', ['token' => $signer->token]))->assertOk();
    }

    public function test_a_signed_link_still_refuses_to_sign_twice(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();
        $signer->forceFill([
            'status'    => \App\Modules\ESign\Enums\SignerStatus::Signed->value,
            'signed_at' => now(),
        ])->save();

        // Looking is allowed; signing again is not.
        $this->postJson(route('esign.portal.sign', ['token' => $signer->token]), [
            'otp' => '123456', 'signature_data' => 'x', 'signature_type' => 'typed',
        ])->assertStatus(403);
    }

    public function test_a_stranger_cannot_fetch_the_document(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);

        // The token is the credential. Without the right one there is no document.
        $this->get(route('esign.portal.document', ['token' => Str::random(64)]))->assertNotFound();
    }

    public function test_the_signed_copy_is_stored_and_hashed(): void
    {
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        $signed = "%PDF-1.4\nsigned by Sipho\n%%EOF";

        app(EnvelopeDocumentService::class)->storeExecuted($envelope, $signed);
        $envelope->refresh();

        $this->assertNotNull($envelope->executed_document_path);
        $this->assertSame(hash('sha256', $signed), $envelope->executed_hash);
        // "The document" becomes the executed copy, so the next signer stamps the one carrying the
        // signature before theirs rather than a fresh original.
        $this->assertSame($signed, app(EnvelopeDocumentService::class)->currentBytes($envelope));
    }

    public function test_something_that_is_not_a_pdf_is_refused_rather_than_stored(): void
    {
        Mail::fake();
        $user = $this->user();
        $envelope = $this->envelopeWithDocument($user);
        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);
        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();

        // A signing link must not be a way to replace the contract with a file of your choosing.
        $this->postJson(route('esign.portal.sign', ['token' => $signer->token]), [
            'otp'            => '123456',
            'signature_data' => 'Sipho Khumalo',
            'signature_type' => 'typed',
            'document'       => 'data:application/pdf;base64,' . base64_encode('<?php echo "not a pdf";'),
        ])->assertStatus(422);

        $this->assertNull($envelope->fresh()->executed_document_path);
    }
}
