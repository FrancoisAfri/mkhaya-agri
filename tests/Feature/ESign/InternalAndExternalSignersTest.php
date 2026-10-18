<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Two kinds of signer, and they are not the same problem.
 *
 * A colleague has an account: they are picked from a list, their address comes from their record,
 * and the link puts them behind their own login. An outside party has none of that: they are typed
 * in, and the page they land on has to say whose document it is, because they may only half
 * recognise the company asking.
 */
class InternalAndExternalSignersTest extends ESignTestCase
{
    /** The picker refuses to guess a tenant, so everybody in these tests belongs to one. */
    private const COMPANY = 1;

    private function staff(string $first = 'Nomsa', string $last = 'Dlamini'): User
    {
        $user = User::factory()->create([
            'email' => strtolower($first) . Str::random(6) . '@example.test',
            'company_id' => self::COMPANY,
        ]);
        $this->grantCrudPermissions($user);

        // tenantPeople() joins hr_people, so a user with no employment record is not offered.
        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => $first, 'surname' => $last,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF");
    }

    public function test_an_internal_signer_is_taken_from_their_own_record(): void
    {
        $author = $this->staff('Thabo', 'Mokoena');
        $colleague = $this->staff('Nomsa', 'Dlamini');

        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Appointment letter',
            'document_type' => 'appointment_letter',
            'signers'       => [[
                'kind' => 'internal', 'user_id' => $colleague->id, 'role' => 'employee',
                // Deliberately wrong, and deliberately ignored: a client that could post a
                // colleague's id beside a stranger's address would send the document there while
                // the audit trail named the colleague.
                'name' => 'Someone Else', 'email' => 'attacker@example.test',
            ]],
        ])->assertRedirect();

        $signer = Signer::whereHas('envelope', fn ($q) => $q->where('title', 'Appointment letter'))->firstOrFail();

        $this->assertSame($colleague->id, $signer->user_id);
        $this->assertSame($colleague->email, $signer->email);
        $this->assertStringContainsString('Nomsa', $signer->name);
    }

    /**
     * Signing is not employment.
     *
     * The picker inner-joined hr_people, so it offered only people with an employment record —
     * 6 of 44 active users here, and none at all on a company that keeps staff records elsewhere.
     * A director, a bookkeeper, an accountant with a login: all sign things, none necessarily has
     * a job here. The screen said "nobody from your organisation is available" on a system full of
     * people.
     */
    public function test_a_colleague_with_no_employment_record_can_still_be_chosen(): void
    {
        $author = $this->staff('Thabo', 'Mokoena');

        // An account and an address, and nothing in hr_people.
        $director = User::factory()->create([
            'email' => 'nomsa.dlamini' . Str::random(4) . '@example.test',
            'company_id' => self::COMPANY,
        ]);

        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Board resolution',
            'document_type' => 'other',
            'signers'       => [['kind' => 'internal', 'user_id' => $director->id, 'role' => 'authorized_signatory']],
        ])->assertSessionHasNoErrors();

        $signer = Signer::whereHas('envelope', fn ($q) => $q->where('title', 'Board resolution'))->firstOrFail();

        $this->assertSame($director->id, $signer->user_id);
        $this->assertSame($director->email, $signer->email);
        // Named from the address when there is no employment record — an unnamed row is unpickable.
        $this->assertStringContainsString('Nomsa', $signer->name);
    }

    /**
     * The two editions disagree about what "no company" means, and only one of them is a leak.
     */
    public function test_a_standalone_install_still_offers_its_people(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $author = User::factory()->create(['company_id' => null]);
        $this->grantCrudPermissions($author);
        $colleague = User::factory()->create([
            'email' => 'standalone' . Str::random(5) . '@example.test', 'company_id' => null,
        ]);

        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Standalone letter',
            'document_type' => 'other',
            'signers'       => [['kind' => 'internal', 'user_id' => $colleague->id, 'role' => 'signer']],
        ])->assertSessionHasNoErrors();

        $signer = Signer::whereHas('envelope', fn ($q) => $q->where('title', 'Standalone letter'))->firstOrFail();
        $this->assertSame($colleague->id, $signer->user_id);
    }

    public function test_a_hosted_install_with_no_tenant_offers_nobody(): void
    {
        config(['edition.edition' => 'saas']);

        $author = User::factory()->create(['company_id' => null]);
        $this->grantCrudPermissions($author);
        $anyone = User::factory()->create(['company_id' => 424242]);

        // Fails closed: a dropdown of every tenant's staff is the kind of leak nobody reports.
        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Should not exist either',
            'document_type' => 'other',
            'signers'       => [['kind' => 'internal', 'user_id' => $anyone->id, 'role' => 'signer']],
        ])->assertSessionHasErrors('signers.0.user_id');
    }

    public function test_an_external_signer_is_taken_from_what_was_typed(): void
    {
        $author = $this->staff('Thabo', 'Mokoena');

        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Supply agreement',
            'document_type' => 'contract',
            'signers'       => [[
                'kind' => 'external', 'name' => 'Sipho Khumalo',
                'email' => 'sipho@supplier.test', 'role' => 'signer',
            ]],
        ])->assertRedirect();

        $signer = Signer::whereHas('envelope', fn ($q) => $q->where('title', 'Supply agreement'))->firstOrFail();

        $this->assertNull($signer->user_id);
        $this->assertSame('sipho@supplier.test', $signer->email);
    }

    public function test_a_user_from_another_tenant_cannot_be_named_as_a_colleague(): void
    {
        $author = $this->staff('Thabo', 'Mokoena');

        // A real user id, belonging to another tenant — which is what must not be offered now
        // that an employment record is no longer the test.
        $outsider = User::factory()->create([
            'email' => 'outsider' . Str::random(6) . '@example.test',
            'company_id' => 999999,
        ]);

        $this->actingAs($author)->post(route('esign.envelopes.store'), [
            'document'      => $this->pdf(),
            'title'         => 'Should not exist',
            'document_type' => 'other',
            'signers'       => [['kind' => 'internal', 'user_id' => $outsider->id, 'role' => 'signer']],
        ])->assertSessionHasErrors('signers.0.user_id');

        $this->assertNull(Envelope::where('title', 'Should not exist')->first());
    }

    public function test_a_colleagues_link_sends_them_to_log_in(): void
    {
        Mail::fake();
        $author = $this->staff('Thabo', 'Mokoena');
        $colleague = $this->staff('Nomsa', 'Dlamini');

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Internal', 'document_type' => 'other'],
            signers: [['name' => 'Nomsa Dlamini', 'email' => $colleague->email, 'user_id' => $colleague->id]],
            createdBy: $author->id,
        );
        app(EnvelopeDocumentService::class)->store($envelope, $this->pdf());
        app(ESignService::class)->send($envelope->fresh('signers'), $author->id);

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();

        // Signed out, the colleague is put behind their own login rather than treated as a
        // stranger who happens to hold a token.
        $this->get(route('esign.portal.view', ['token' => $signer->token]))
            ->assertRedirect(route('login'));

        // Signed in, they reach the document.
        $this->actingAs($colleague)
            ->get(route('esign.portal.view', ['token' => $signer->token]))
            ->assertOk();
    }

    public function test_an_outside_signer_is_shown_whose_document_it_is(): void
    {
        Mail::fake();
        $author = $this->staff('Thabo', 'Mokoena');

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'External', 'document_type' => 'contract'],
            signers: [['name' => 'Sipho Khumalo', 'email' => 'sipho@supplier.test']],
            createdBy: $author->id,
        );
        app(EnvelopeDocumentService::class)->store($envelope, $this->pdf());
        app(ESignService::class)->send($envelope->fresh('signers'), $author->id);

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();

        $this->get(route('esign.portal.view', ['token' => $signer->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isInternal', false)
                ->has('company.name'));
    }
}
