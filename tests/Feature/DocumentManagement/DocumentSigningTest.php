<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Enums\SignerStatus;
use App\Modules\ESign\Models\EsignField;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\SignatureService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sending a document for signature.
 *
 * The signing itself belongs to the ESign module and is tested there. What is tested here is the
 * join: that the bytes people sign cannot change underneath them, that the document's own status
 * follows the envelope rather than drifting from it, and that the executed copy comes back as a
 * version rather than replacing what was sent.
 */
class DocumentSigningTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'sig+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]));
    }

    private function signing(): SignatureService
    {
        return app(SignatureService::class);
    }

    private function document(string $mime = 'application/pdf'): Document
    {
        $path = tempnam(sys_get_temp_dir(), 'sig');
        $isPdf = $mime === 'application/pdf';

        // Real content for the type claimed: the upload guard reads the bytes, so a .txt file
        // containing PDF bytes is refused — correctly, and it would fail this helper rather than
        // the thing under test.
        file_put_contents($path, $isPdf ? "%PDF-1.4\nagreement\n%%EOF" : "Just some notes.\n");
        $file = new UploadedFile($path, $isPdf ? 'agreement.pdf' : 'notes.txt', $mime, null, true);

        return app(DocumentService::class)->store($file, ['title' => 'Service agreement'])['document'];
    }

    private function signatories(int $n = 1): array
    {
        return collect(range(1, $n))->map(fn ($i) => [
            'name' => "Signer {$i}", 'email' => "signer{$i}@example.test", 'order' => $i,
        ])->all();
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    public function test_sending_creates_an_envelope_and_marks_the_document(): void
    {
        $document = $this->document();

        $result = $this->signing()->send($document, $this->signatories(2));

        $this->assertNotNull($result['envelope'], $result['error'] ?? '');
        $this->assertSame(SignatureService::AWAITING, $document->fresh()->signature_status);
        $this->assertSame(2, $result['envelope']->signers()->count());
    }

    public function test_the_bytes_people_sign_cannot_change_underneath_them(): void
    {
        $document = $this->document();
        $original = Storage::disk('documents')->get($document->currentVersion->path);

        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        // Replaced after sending — which is allowed, and must not alter what was sent for signing.
        $replacement = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($replacement, "%PDF-1.4\nDIFFERENT TERMS\n%%EOF");
        app(DocumentService::class)->replace(
            $document->fresh(),
            new UploadedFile($replacement, 'agreement.pdf', 'application/pdf', null, true),
            'Changed after sending',
        );

        $this->assertSame($original, Storage::disk('documents')->get($envelope->document_path));
        // And the hash recorded at send time still describes it, which is what makes it provable.
        $this->assertSame(hash('sha256', $original), $envelope->document_hash);
    }

    public function test_a_document_cannot_be_out_for_signature_twice(): void
    {
        $document = $this->document();
        $this->signing()->send($document, $this->signatories());

        // Two open envelopes would each produce a signed copy, and nothing would say which is the
        // executed one.
        $second = $this->signing()->send($document->fresh(), $this->signatories());

        $this->assertNull($second['envelope']);
    }

    public function test_only_a_pdf_can_be_sent(): void
    {
        $result = $this->signing()->send($this->document('text/plain'), $this->signatories());

        // Converting a document somebody is about to sign changes what they are signing.
        $this->assertNull($result['envelope']);
        $this->assertStringContainsString('PDF', $result['error']);
    }

    public function test_sending_without_anybody_to_sign_is_refused(): void
    {
        $this->assertNull($this->signing()->send($this->document(), [])['envelope']);
    }

    // ── Field placement ──────────────────────────────────────────────────────

    public function test_fields_are_placed_against_the_right_signatory(): void
    {
        $document = $this->document();

        $envelope = $this->signing()->send($document, $this->signatories(2), [
            ['type' => EsignField::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.8, 'signer' => 0],
            ['type' => EsignField::DATE, 'page' => 1, 'x' => 0.6, 'y' => 0.8, 'signer' => 1],
            ['type' => EsignField::INITIALS, 'page' => 2, 'x' => 0.9, 'y' => 0.95, 'signer' => 0],
        ])['envelope'];

        $fields = $envelope->fields()->get();

        $this->assertCount(3, $fields);
        $this->assertSame(EsignField::SIGNATURE, $fields->first()->type);
        // Ordered by page then down the page — the order somebody filling them in meets them.
        $this->assertSame(2, $fields->last()->page);
    }

    public function test_a_field_placed_off_the_page_is_brought_back_onto_it(): void
    {
        $document = $this->document();

        $envelope = $this->signing()->send($document, $this->signatories(), [
            ['type' => EsignField::SIGNATURE, 'x' => 1.8, 'y' => -0.4],
        ])['envelope'];

        // Invisible to whoever has to sign it otherwise, and they cannot report what they cannot see.
        $field = $envelope->fields()->first();
        $this->assertSame(1.0, $field->x);
        $this->assertSame(0.0, $field->y);
    }

    public function test_a_signing_request_without_placed_fields_is_still_valid(): void
    {
        // Signing the document as a whole is how the module worked before placement existed, and
        // remains the simplest case.
        $this->assertNotNull($this->signing()->send($this->document(), $this->signatories())['envelope']);
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function test_one_signature_out_of_two_reads_as_partially_signed(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories(2))['envelope'];

        $envelope->forceFill(['status' => EnvelopeStatus::InProgress])->save();
        $envelope->signers()->first()->forceFill([
            'status' => SignerStatus::Signed->value, 'signed_at' => now(),
        ])->save();

        // Not a state the envelope stores; it is what somebody watching actually wants to know.
        $this->assertSame(SignatureService::PARTIAL, $this->signing()->sync($document->fresh()));
    }

    public function test_a_refusal_is_reported_as_declined_whatever_the_envelope_says(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories(2))['envelope'];

        $envelope->signers()->first()->forceFill([
            'status' => SignerStatus::Declined->value, 'declined_reason' => 'Wrong terms',
        ])->save();

        $this->assertSame(SignatureService::DECLINED, $this->signing()->sync($document->fresh()));
    }

    public function test_a_voided_envelope_reads_as_cancelled(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];
        $envelope->forceFill(['status' => EnvelopeStatus::Voided])->save();

        $this->assertSame(SignatureService::CANCELLED, $this->signing()->sync($document->fresh()));
    }

    public function test_progress_reports_who_has_signed(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories(3))['envelope'];
        $envelope->signers()->first()->forceFill([
            'status' => SignerStatus::Signed->value, 'signed_at' => now(),
        ])->save();

        $progress = $this->signing()->progress($document->fresh());

        $this->assertSame(1, $progress['signed']);
        $this->assertSame(3, $progress['total']);
        $this->assertCount(3, $progress['signers']);
    }

    // ── Coming back ──────────────────────────────────────────────────────────

    public function test_the_signed_copy_returns_as_a_new_version(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        $signedPath = 'esign/signed/' . Str::uuid() . '.pdf';
        Storage::disk('documents')->put($signedPath, "%PDF-1.4\nSIGNED\n%%EOF");
        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'signed_document_path' => $signedPath,
            'disk' => 'documents',
        ])->save();

        $this->signing()->sync($document->fresh());
        $document = $document->fresh();

        // A new version, not a replacement: "what did we send them" and "what came back" are
        // different questions and an audit needs both.
        $this->assertSame(2, $document->versions()->count());
        $this->assertStringContainsString('SIGNED', Storage::disk('documents')->get($document->currentVersion->path));
        $this->assertSame('2.0', $document->currentVersion->label());
        $this->assertTrue($document->events()->pluck('action')->contains(DocumentEvent::SIGNED));
    }

    public function test_syncing_twice_does_not_file_the_signed_copy_twice(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        $signedPath = 'esign/signed/' . Str::uuid() . '.pdf';
        Storage::disk('documents')->put($signedPath, "%PDF-1.4\nSIGNED\n%%EOF");
        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed, 'signed_document_path' => $signedPath, 'disk' => 'documents',
        ])->save();

        $this->signing()->sync($document->fresh());
        $this->signing()->sync($document->fresh());

        // The sweep and the screen both call sync; a second copy every time would grow the history
        // without anything having happened.
        $this->assertSame(2, $document->fresh()->versions()->count());
    }

    // ── Which file comes back, and what happens to the one we sent ───────────

    /**
     * `signed_document_path` holds the signature CERTIFICATE — a standalone page listing who
     * signed, when and from where. The signed document is `executed_document_path`: the original
     * pages carrying each signature, stamped as the envelope moves down the signing order.
     *
     * Filing the certificate as "(signed).pdf" handed people a version that did not contain their
     * contract, which is the wrong file under the right name.
     */
    public function test_the_executed_document_is_filed_rather_than_the_certificate(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        $certificate = 'esign/cert/' . Str::uuid() . '.pdf';
        $executed = 'esign/executed/' . Str::uuid() . '.pdf';
        Storage::disk('documents')->put($certificate, "%PDF-1.4\nCERTIFICATE OF SIGNING\n%%EOF");
        Storage::disk('documents')->put($executed, "%PDF-1.4\nagreement SIGNED BY BOTH\n%%EOF");

        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'signed_document_path' => $certificate,
            'executed_document_path' => $executed,
            'disk' => 'documents',
        ])->save();

        $this->signing()->sync($document->fresh());
        $document = $document->fresh();

        $filed = Storage::disk('documents')->get($document->currentVersion->path);
        $this->assertStringContainsString('SIGNED BY BOTH', $filed,
            'The certificate was filed as the signed copy instead of the signed document.');
        $this->assertStringNotContainsString('CERTIFICATE OF SIGNING', $filed);
        $this->assertStringContainsString('(signed)', $document->currentVersion->original_name);
    }

    /** An envelope signed before the stamping path existed still files something, named honestly. */
    public function test_without_an_executed_copy_the_certificate_is_filed_and_named_as_one(): void
    {
        $document = $this->document();
        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        $certificate = 'esign/cert/' . Str::uuid() . '.pdf';
        Storage::disk('documents')->put($certificate, "%PDF-1.4\nCERTIFICATE OF SIGNING\n%%EOF");
        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'signed_document_path' => $certificate,
            'disk' => 'documents',
        ])->save();

        $this->signing()->sync($document->fresh());
        $document = $document->fresh();

        $this->assertSame(2, $document->versions()->count());
        $this->assertStringContainsString('signature certificate', $document->currentVersion->original_name,
            'A certificate was filed under a name that claims it is the signed document.');
    }

    /**
     * The point of the whole exercise: after signing there are two files, not one. The unsigned
     * copy is what was sent and what the signature is a signature OF; losing it leaves nothing to
     * check the executed copy against.
     */
    public function test_the_unsigned_original_survives_alongside_the_signed_copy(): void
    {
        $document = $this->document();
        $unsignedVersionId = $document->currentVersion->id;
        $unsignedPath = $document->currentVersion->path;
        $unsignedBytes = Storage::disk('documents')->get($unsignedPath);

        $envelope = $this->signing()->send($document, $this->signatories())['envelope'];

        $executed = 'esign/executed/' . Str::uuid() . '.pdf';
        Storage::disk('documents')->put($executed, "%PDF-1.4\nagreement SIGNED\n%%EOF");
        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'executed_document_path' => $executed,
            'disk' => 'documents',
        ])->save();

        $this->signing()->sync($document->fresh());
        $document = $document->fresh();

        // Both rows are there, and the newer one says which it replaced.
        $this->assertSame(2, $document->versions()->count());
        $this->assertNotSame($unsignedVersionId, $document->current_version_id);
        $this->assertSame($unsignedVersionId, $document->currentVersion->supersedes_id);

        // And the unsigned bytes are still on disk, unchanged.
        $this->assertTrue(Storage::disk('documents')->exists($unsignedPath),
            'The unsigned original was removed when the signed copy came back.');
        $this->assertSame($unsignedBytes, Storage::disk('documents')->get($unsignedPath));
        $this->assertStringNotContainsString('SIGNED', $unsignedBytes);
    }
}
