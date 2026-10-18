<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The envelope screen has one job: show who still has to sign. A nested resource collection is not
 * unwrapped by the outer resource's resolve(), so it shipped `signers: {"data": [...]}` where the
 * page expected an array — v-for iterated the object, found the single key `data`, and drew one
 * blank row. The envelope appeared to have a signer nobody could identify.
 */
class EnvelopeShowsItsSignersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_page_receives_signers_as_a_list_it_can_render(): void
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'p' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'is_platform_admin' => true,
        ]);

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'doc', 'document_type' => 'other'],
            signers: [['name' => 'Sipho Khumalo', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        $res = $this->actingAs($user)->get(route('esign.envelopes.show', $envelope->uuid));
        $signers = $res->viewData('page')['props']['envelope']['signers'] ?? null;

        $this->assertIsList($signers, 'signers arrived wrapped — the page will render a blank row.');
        $this->assertSame('Sipho Khumalo', $signers[0]['name']);
        $this->assertSame('sipho@example.test', $signers[0]['email']);
        $this->assertSame('pending', $signers[0]['status']);
    }

    /** A draft with a signer can be sent, and the signer is told. */
    public function test_a_draft_can_be_sent_to_its_signers(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 's' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'is_platform_admin' => true,
        ]);

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'doc', 'document_type' => 'other'],
            signers: [['name' => 'Sipho Khumalo', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        // Sending is refused without one — the signer would receive a page with nothing on it.
        app(\App\Modules\ESign\Services\EnvelopeDocumentService::class)->store(
            $envelope,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        );

        $this->actingAs($user)
            ->post(route('esign.envelopes.send', $envelope->uuid))
            ->assertRedirect();

        $this->assertSame('sent', $envelope->fresh()->status->value);
        // The token is what the emailed link carries; without it the link cannot resolve.
        $this->assertNotNull($envelope->fresh('signers')->signers->first()->token);
    }
}
