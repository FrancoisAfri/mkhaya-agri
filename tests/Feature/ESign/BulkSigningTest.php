<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Signing everything at once.
 *
 * Somebody joining a company signs four documents on the same morning. One at a time that is four
 * links, four codes and four signatures, and by the third nobody is reading — which is the
 * opposite of what a signature is for.
 *
 * One code covers the batch, but it is written as a separate challenge against each signer, so
 * every document still verifies its own and keeps its own audit line. The saving is the typing,
 * not the evidence.
 */
class BulkSigningTest extends ESignTestCase
{
    private function staff(): User
    {
        $user = User::factory()->create();
        $this->grantCrudPermissions($user);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** @return \Illuminate\Support\Collection<int, Signer> */
    private function awaiting(User $signerUser, int $count)
    {
        $signers = collect();

        for ($i = 1; $i <= $count; $i++) {
            $envelope = app(ESignService::class)->create(
                envelopeData: ['title' => "Document {$i}", 'document_type' => 'other'],
                signers: [['name' => 'Nomsa Dlamini', 'email' => $signerUser->email, 'user_id' => $signerUser->id]],
                createdBy: $this->hrUser->id,
            );

            app(EnvelopeDocumentService::class)->store(
                $envelope,
                UploadedFile::fake()->createWithContent("d{$i}.pdf", "%PDF-1.4\n%%EOF"),
            );

            app(ESignService::class)->send($envelope->fresh('signers'), $this->hrUser->id);
            $signers->push(Signer::where('envelope_id', $envelope->id)->firstOrFail());
        }

        return $signers;
    }

    public function test_the_list_shows_only_what_is_waiting_for_me(): void
    {
        Mail::fake();
        $me = $this->staff();
        $this->awaiting($me, 3);

        // Somebody else's document, addressed to somebody else.
        $other = $this->staff();
        $this->awaiting($other, 2);

        $this->actingAs($me)->get(route('esign.envelopes.mine'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/ESign/MySignatures')
                ->has('pending', 3));
    }

    public function test_one_code_is_issued_for_every_document_in_the_batch(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signers = $this->awaiting($me, 3);

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.otp'), [
            'signer_ids' => $signers->pluck('id')->all(),
        ])->assertOk();

        // A challenge each — so every envelope verifies its own and keeps its own audit line.
        foreach ($signers as $signer) {
            $this->assertNotNull($signer->fresh()->latestPendingOtp(), 'No challenge for one of the batch.');
        }

        // But one email, not three.
        Mail::assertQueued(\App\Modules\ESign\Mail\SignerOtpMail::class, 1);
    }

    public function test_all_of_them_are_signed_with_one_code(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signers = $this->awaiting($me, 3);

        // Issue a known code the way the batch does, so the test signs the way a person would.
        $code = '424242';
        foreach ($signers as $signer) {
            DB::table('esign_otp_challenges')->insert([
                'signer_id' => $signer->id, 'code_hash' => Hash::make($code), 'channel' => 'email',
                'sent_to' => $signer->email, 'expires_at' => now()->addMinutes(15),
                'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids'     => $signers->pluck('id')->all(),
            'otp'            => $code,
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
        ])->assertOk();

        foreach ($signers as $signer) {
            $this->assertNotNull($signer->fresh()->signed_at, "{$signer->envelope->title} was not signed.");
        }
    }

    public function test_i_cannot_sign_a_document_addressed_to_somebody_else(): void
    {
        Mail::fake();
        $me = $this->staff();
        $someoneElse = $this->staff();
        $theirs = $this->awaiting($someoneElse, 1)->first();

        // A signer_id is checked against my own pending list, never trusted on its own.
        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids'     => [$theirs->id],
            'otp'            => '424242',
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
        ])->assertStatus(422);

        $this->assertNull($theirs->fresh()->signed_at);
    }

    public function test_a_wrong_code_signs_nothing(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signers = $this->awaiting($me, 2);

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.otp'), [
            'signer_ids' => $signers->pluck('id')->all(),
        ])->assertOk();

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids'     => $signers->pluck('id')->all(),
            'otp'            => '000000',
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
        ])->assertStatus(422);

        foreach ($signers as $signer) {
            $this->assertNull($signer->fresh()->signed_at);
        }
    }

    // ── Where the signature goes ─────────────────────────────────────────────

    /**
     * The signer says where, and it is written down.
     *
     * Every bulk signature used to land at a fixed spot — last page, eight per cent from the left,
     * twelve per cent up — because the screen had nowhere to ask. That is fine until it lands on
     * top of a clause, and on a batch of twenty it does so twenty times before anybody notices.
     *
     * `esign_fields` has modelled page, x, y and width since the module was built and had never
     * held a row. The stamped PDF shows where the mark ended up; this is the only thing that says
     * it is where the signer put it.
     */
    public function test_where_the_signature_was_placed_is_recorded(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signer = $this->awaiting($me, 1)->first();

        $code = '424242';
        DB::table('esign_otp_challenges')->insert([
            'signer_id' => $signer->id, 'code_hash' => Hash::make($code), 'channel' => 'email',
            'sent_to' => $signer->email, 'expires_at' => now()->addMinutes(15),
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids' => [$signer->id],
            'otp' => $code,
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
            'placements' => [
                $signer->envelope->uuid => ['page' => 2, 'x' => 0.55, 'y' => 0.2, 'width' => 0.3],
            ],
        ])->assertOk();

        $field = \App\Modules\ESign\Models\EsignField::where('signer_id', $signer->id)->first();

        $this->assertNotNull($field, 'Nowhere records where the signature was put.');
        $this->assertSame(2, $field->page);
        $this->assertSame(0.55, $field->x);
        $this->assertSame(0.2, $field->y);
    }

    /** Signing without touching the placement still works, exactly as it did before. */
    public function test_a_signature_with_no_placement_still_signs(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signer = $this->awaiting($me, 1)->first();

        $code = '424242';
        DB::table('esign_otp_challenges')->insert([
            'signer_id' => $signer->id, 'code_hash' => Hash::make($code), 'channel' => 'email',
            'sent_to' => $signer->email, 'expires_at' => now()->addMinutes(15),
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids' => [$signer->id],
            'otp' => $code,
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
        ])->assertOk();

        $this->assertNotNull($signer->fresh()->signed_at);
        $this->assertNull(\App\Modules\ESign\Models\EsignField::where('signer_id', $signer->id)->first(),
            'A placement was invented for a signer who never chose one.');
    }

    /** A coordinate off the page is invisible to whoever has to read the document. */
    public function test_a_placement_outside_the_page_is_refused(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signer = $this->awaiting($me, 1)->first();

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids' => [$signer->id],
            'otp' => '424242',
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
            'placements' => [
                $signer->envelope->uuid => ['page' => 1, 'x' => 4.2, 'y' => 0.2, 'width' => 0.3],
            ],
        ])->assertStatus(422);
    }

    /**
     * A placement is only written once the signature itself succeeded — a field recorded against a
     * refused signature would be a record of something that did not happen.
     */
    public function test_no_placement_is_recorded_when_the_code_is_wrong(): void
    {
        Mail::fake();
        $me = $this->staff();
        $signer = $this->awaiting($me, 1)->first();

        DB::table('esign_otp_challenges')->insert([
            'signer_id' => $signer->id, 'code_hash' => Hash::make('424242'), 'channel' => 'email',
            'sent_to' => $signer->email, 'expires_at' => now()->addMinutes(15),
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($me)->postJson(route('esign.envelopes.mine.sign'), [
            'signer_ids' => [$signer->id],
            'otp' => '999999',
            'signature_data' => 'Nomsa Dlamini',
            'signature_type' => 'typed',
            'placements' => [
                $signer->envelope->uuid => ['page' => 1, 'x' => 0.5, 'y' => 0.2, 'width' => 0.3],
            ],
        ]);

        $this->assertNull($signer->fresh()->signed_at);
        $this->assertNull(\App\Modules\ESign\Models\EsignField::where('signer_id', $signer->id)->first(),
            'A placement was recorded for a signature that was refused.');
    }
}
