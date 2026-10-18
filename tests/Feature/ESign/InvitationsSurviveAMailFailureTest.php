<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Mail\SignerInviteMail;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * A mail server that is slow to answer must not undo a send that already happened.
 *
 * Reported from production: "Connection to mail.hub.mkhayamk.co.za:25 timed out" — thrown from
 * `executeCommand("\r\n.\r\n")`, which is the end-of-DATA step. The message had been transmitted in
 * full and accepted; only the server's acknowledgement failed to come back in time. The recipient
 * got the email.
 *
 * The send ran inside `DB::transaction()`, and the queue is `sync`, so the SMTP conversation
 * happened inline and the throw rolled everything back. The envelope reverted to a draft and the
 * signer's token was undone — so the link in the invitation the recipient was holding pointed at a
 * token that no longer existed, and nothing recorded that any of it had occurred.
 *
 * A sent email cannot be unsent. The database has to commit first.
 */
class InvitationsSurviveAMailFailureTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'p' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1,
        ]);
    }

    /** @param list<array{name: string, email: string, role: string}> $signers */
    private function envelope(User $user, array $signers): Envelope
    {
        return app(ESignService::class)->create(
            envelopeData: ['title' => 'Probe', 'document_type' => 'contract'],
            signers: $signers,
            createdBy: $user->id,
        )->fresh('signers');
    }

    private function timeOutTheMailServer(): void
    {
        // The shape of the reported failure: the transport throws after the message is away.
        Mail::shouldReceive('to')->andThrow(
            new TransportException('Connection to "mail.example.test:25" timed out.')
        );
    }

    public function test_a_timeout_does_not_undo_the_send(): void
    {
        $user = $this->user();
        $envelope = $this->envelope($user, [
            ['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer'],
        ]);

        $this->timeOutTheMailServer();

        try {
            app(ESignService::class)->send($envelope, $user->id);
        } catch (ValidationException) {
            // Expected: the caller is told the invitation did not go out.
        }

        $this->assertSame('sent', $envelope->fresh()->status->value,
            'The envelope was sent — a mail server being slow does not change that.');
    }

    public function test_the_link_in_the_delivered_email_still_works(): void
    {
        /*
         * The damage that was actually done. The recipient holds a real invitation; the token it
         * points at has to exist, or the only person who can act on the envelope cannot.
         */
        $user = $this->user();
        $envelope = $this->envelope($user, [
            ['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer'],
        ]);

        $this->timeOutTheMailServer();

        try {
            app(ESignService::class)->send($envelope, $user->id);
        } catch (ValidationException) {
        }

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();

        $this->assertNotNull($signer->token, 'The token the invitation quotes has to survive.');
        $this->assertSame('notified', $signer->status->value);

        $this->get(route('esign.portal.view', ['token' => $signer->token], absolute: false))
            ->assertOk();
    }

    public function test_the_sender_is_told_who_never_got_their_invitation(): void
    {
        /*
         * There is no way to send an invitation again — `send()` accepts a draft only, and the
         * envelope is no longer one. Somebody who is not told has no way to find out except by
         * waiting for a signature that is never coming.
         */
        $user = $this->user();
        $envelope = $this->envelope($user, [
            ['name' => 'Sipho Ndlovu', 'email' => 'sipho@example.test', 'role' => 'signer'],
        ]);

        $this->timeOutTheMailServer();

        try {
            app(ESignService::class)->send($envelope, $user->id);
            $this->fail('An undelivered invitation should have been reported.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Sipho Ndlovu', implode(' ', $e->errors()['email'] ?? []));
        }
    }

    public function test_one_bad_address_does_not_cost_the_others_their_invitation(): void
    {
        /*
         * On a sync queue an uncaught throw ends the loop, so the signers after the failing one
         * were never even attempted — and on a rolled-back transaction, nobody could sign at all.
         */
        $user = $this->user();
        $envelope = $this->envelope($user, [
            ['name' => 'First', 'email' => 'first@example.test', 'role' => 'signer'],
            ['name' => 'Second', 'email' => 'second@example.test', 'role' => 'signer'],
            ['name' => 'Third', 'email' => 'third@example.test', 'role' => 'signer'],
        ]);

        $attempted = [];

        Mail::shouldReceive('to')->andReturnUsing(function (string $email) use (&$attempted) {
            $attempted[] = $email;

            if ($email === 'second@example.test') {
                throw new TransportException('Connection timed out.');
            }

            return new class {
                public function queue($mailable): void {}
            };
        });

        try {
            app(ESignService::class)->send($envelope, $user->id);
        } catch (ValidationException) {
        }

        $this->assertSame(
            ['first@example.test', 'second@example.test', 'third@example.test'],
            $attempted,
            'The signer after the failing one still has to be tried.',
        );

        foreach (Signer::where('envelope_id', $envelope->id)->get() as $signer) {
            $this->assertNotNull($signer->token, "{$signer->email} lost their token");
        }
    }

    public function test_the_ordinary_send_is_unchanged(): void
    {
        Mail::fake();

        $user = $this->user();
        $envelope = $this->envelope($user, [
            ['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer'],
        ]);

        $result = app(ESignService::class)->send($envelope, $user->id);

        Mail::assertQueued(SignerInviteMail::class);

        $this->assertSame('sent', $result->status->value);
        $this->assertNotNull(Signer::where('envelope_id', $envelope->id)->value('token'));
    }
}
