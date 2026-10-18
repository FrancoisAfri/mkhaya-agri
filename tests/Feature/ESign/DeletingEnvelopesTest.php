<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * What may be deleted, and what must not be.
 *
 * Envelopes accumulate: abandoned drafts and cancelled ones sit in the list forever with no way to
 * clear them. But an envelope is also the record of who signed what, and being able to make a
 * signed one disappear would undermine every envelope in the system rather than just that one.
 *
 * So the line is drawn at whether anything is out in the world: a draft has been sent nowhere, a
 * voided one has already been cancelled and the cancellation recorded. Anything in between is
 * sitting in somebody's inbox as a live link, and anything completed is evidence.
 */
class DeletingEnvelopesTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'e' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1,
        ]);
    }

    private function envelope(User $user, string $status = 'draft'): Envelope
    {
        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Probe', 'document_type' => 'contract'],
            signers: [['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        if ($status !== 'draft') {
            $envelope->forceFill(['status' => $status])->save();
        }

        return $envelope->fresh('signers');
    }

    public function test_a_draft_can_be_deleted(): void
    {
        // Nothing has been sent anywhere, so nothing is lost and nobody is left holding a link.
        $user = $this->user();
        $envelope = $this->envelope($user);

        app(ESignService::class)->delete($envelope, $user->id);

        $this->assertSoftDeleted('esign_envelopes', ['id' => $envelope->id]);
    }

    public function test_a_voided_envelope_can_be_deleted(): void
    {
        // It has already been cancelled and the cancellation recorded; this is tidying up.
        $user = $this->user();
        $envelope = $this->envelope($user, EnvelopeStatus::Voided->value);

        app(ESignService::class)->delete($envelope, $user->id);

        $this->assertSoftDeleted('esign_envelopes', ['id' => $envelope->id]);
    }

    public function test_a_completed_envelope_can_never_be_deleted(): void
    {
        /*
         * The one that matters. The signatures, the audit trail and the certificate are the whole
         * reason the module exists — a system where signed evidence can be made to disappear is
         * not one anybody should rely on for a contract.
         */
        $user = $this->user();
        $envelope = $this->envelope($user, EnvelopeStatus::Completed->value);

        $this->expectException(ValidationException::class);

        app(ESignService::class)->delete($envelope, $user->id);
    }

    public function test_an_envelope_out_with_signers_cannot_be_deleted(): void
    {
        /*
         * People are holding live links to it. Deleting turns those into dead ends with no
         * explanation — voiding tells them it is cancelled, and records why.
         */
        Mail::fake();

        $user = $this->user();
        $envelope = $this->envelope($user);

        app(ESignService::class)->send($envelope, $user->id);

        $this->expectException(ValidationException::class);

        app(ESignService::class)->delete($envelope->fresh(), $user->id);
    }

    public function test_a_deletion_is_recorded_before_it_happens(): void
    {
        // Written first on purpose: an entry attached afterwards belongs to a row the module no
        // longer reads, which is the same as not recording it.
        $user = $this->user();
        $envelope = $this->envelope($user);

        app(ESignService::class)->delete($envelope, $user->id);

        $this->assertDatabaseHas('esign_audit_entries', [
            'envelope_id' => $envelope->id,
            'event'       => 'deleted',
        ]);
    }

    public function test_a_deleted_envelope_is_gone_from_the_list(): void
    {
        $user = $this->user();
        $envelope = $this->envelope($user);

        app(ESignService::class)->delete($envelope, $user->id);

        $this->assertNull(Envelope::where('uuid', $envelope->uuid)->first());
        // Soft-deleted, so a mistake is recoverable and the audit entries still have their row.
        $this->assertNotNull(Envelope::withTrashed()->where('uuid', $envelope->uuid)->first());
    }
}
