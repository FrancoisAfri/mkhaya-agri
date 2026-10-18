<?php

namespace Tests\Feature\ESign;

use App\Core\User\Models\User;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The emailed signing link has to open for somebody with no account — that is the entire point of
 * it. It did not: routes/modules/esign.php is required from inside a group carrying `auth`, group
 * middleware is inherited and cannot be dropped from within, so every link bounced the signer to
 * /login. It worked only for whoever tested it while already signed in.
 */
class SigningLinkOpensForAGuestTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_emailed_link_opens_for_somebody_who_is_not_logged_in(): void
    {
        Mail::fake();

        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'p' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1,
        ]);

        $envelope = app(ESignService::class)->create(
            envelopeData: ['title' => 'Probe', 'document_type' => 'contract'],
            signers: [['name' => 'Sipho', 'email' => 'sipho@example.test', 'role' => 'signer']],
            createdBy: $user->id,
        );

        app(ESignService::class)->send($envelope->fresh('signers'), $user->id);

        $signer = Signer::where('envelope_id', $envelope->id)->firstOrFail();
        $url = route('esign.portal.view', ['token' => $signer->token], absolute: false);

        $res = $this->get($url);

        // Explicitly not authenticated: this is the external signer's first contact with us.
        $this->assertGuest();

        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Modules/ESign/Portal/Sign')
            ->where('signer.name', 'Sipho'));

        // Opening it is recorded, which is what the audit trail is for.
        $this->assertSame('viewed', $signer->fresh()->status->value);
    }
}
