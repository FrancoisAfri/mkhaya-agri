<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentShareCodeNotification;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\ShareService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Proving the person opening a link is the person it was sent to.
 *
 * A share could already carry a password, and a password does less than it looks like it does: it
 * travels in the same email as the link, so anybody holding the email holds both. It defends
 * against a link pasted into a chat window and nothing else — and a forwarded email is the ordinary
 * way a shared document reaches somebody it was never meant for.
 *
 * A code sent to the recorded address is a different claim: it proves control of that mailbox at
 * the moment of opening.
 */
class ShareEmailVerificationTest extends TestCase
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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    private function sharer(): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'shr+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach (['document.view', 'document.upload', 'document.share'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo(['document.view', 'document.upload', 'document.share']);
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\ncontract\n%%EOF");

        return new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true);
    }

    /** @return array{share: DocumentShare, token: string, document: Document} */
    private function share(array $options = []): array
    {
        $this->actingAs($this->sharer());

        $document = app(DocumentService::class)->store($this->pdf(), [])['document'];

        $made = app(ShareService::class)->create($document, array_merge([
            'recipient_email' => 'auditor@example.test',
            'require_email_code' => true,
            'allow_download' => true,
        ], $options));

        // The recipient is outside the organisation and has no session here.
        auth()->logout();

        return ['share' => $made['share'], 'token' => $made['token'], 'document' => $document];
    }

    /** Reads the code out of the notification, the way the recipient reads it out of their inbox. */
    private function codeSentTo(string $email): string
    {
        $sent = null;

        Notification::assertSentOnDemand(
            DocumentShareCodeNotification::class,
            function ($notification, $channels, $notifiable) use ($email, &$sent) {
                if (($notifiable->routes['mail'] ?? null) === $email) {
                    $sent = $notification->code;

                    return true;
                }

                return false;
            },
        );

        return (string) $sent;
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    public function test_the_document_is_not_shown_before_the_code_is_answered(): void
    {
        ['token' => $token] = $this->share();

        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('locked', true)
                ->where('gate', 'code')
                // Not even the title. A title is often the sensitive part.
                ->where('document', null));
    }

    public function test_the_bytes_are_refused_before_the_code_is_answered(): void
    {
        /*
         * Enforced on the route that serves the file, not only on the landing page. A gate only the
         * landing page honours is one somebody walks around by pasting the /view URL — which they
         * would have from that page's own markup.
         */
        ['token' => $token] = $this->share();

        $this->get(route('documents.shared.view', $token))->assertNotFound();
        $this->get(route('documents.shared.download', $token))->assertNotFound();
    }

    public function test_answering_the_code_opens_it(): void
    {
        Notification::fake();

        ['token' => $token] = $this->share();

        $this->post(route('documents.shared.send-code', $token))->assertRedirect();

        $code = $this->codeSentTo('auditor@example.test');

        $this->post(route('documents.shared.verify-code', $token), ['code' => $code])
            ->assertRedirect(route('documents.shared.show', $token));

        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locked', false));

        $this->get(route('documents.shared.view', $token))->assertOk();
    }

    public function test_the_address_is_only_ever_shown_masked(): void
    {
        /*
         * Showing it in full would hand the recipient's address to whoever has the link, which is
         * the thing this feature exists to stop mattering.
         */
        ['share' => $share, 'token' => $token] = $this->share();

        $masked = $share->maskedRecipient();

        $this->assertStringNotContainsString('auditor@', (string) $masked);
        $this->assertStringContainsString('@example.test', (string) $masked);

        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recipient', $masked));
    }

    // ── The code itself ──────────────────────────────────────────────────────

    public function test_the_code_is_not_stored_in_a_form_anybody_could_read(): void
    {
        // A code sitting in plaintext is a password with a shorter life, in the table an attacker
        // would already be reading if they could read anything at all.
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();

        $this->post(route('documents.shared.send-code', $token));

        $code = $this->codeSentTo('auditor@example.test');

        $this->assertNotSame($code, $share->fresh()->code_hash);
        $this->assertStringNotContainsString($code, (string) $share->fresh()->code_hash);
    }

    public function test_a_wrong_code_is_refused_and_costs_an_attempt(): void
    {
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();
        $this->post(route('documents.shared.send-code', $token));

        $this->post(route('documents.shared.verify-code', $token), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGreaterThan(0, (int) $share->fresh()->code_attempts);
        $this->get(route('documents.shared.view', $token))->assertNotFound();
    }

    public function test_guessing_is_cut_off_after_a_few_tries(): void
    {
        /*
         * Six digits is a million possibilities — plenty against a person typing, nothing at all
         * against a script. The link is public, so the script is the case to design for.
         */
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();
        $this->post(route('documents.shared.send-code', $token));

        $real = $this->codeSentTo('auditor@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('documents.shared.verify-code', $token), ['code' => '000001']);
        }

        // Even the right code is refused once the attempts are spent — otherwise the limit only
        // slows a script down rather than stopping it.
        $this->post(route('documents.shared.verify-code', $token), ['code' => $real])
            ->assertSessionHasErrors('code');

        $this->get(route('documents.shared.view', $token))->assertNotFound();
    }

    public function test_a_code_is_spent_when_it_is_used(): void
    {
        // A code that stayed live for its full fifteen minutes after being used is a second key to
        // the same door, sitting in an inbox.
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();
        $this->post(route('documents.shared.send-code', $token));

        $code = $this->codeSentTo('auditor@example.test');
        $this->post(route('documents.shared.verify-code', $token), ['code' => $code]);

        $this->assertNull($share->fresh()->code_hash);
    }

    public function test_an_expired_code_does_not_work(): void
    {
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();
        $this->post(route('documents.shared.send-code', $token));

        $code = $this->codeSentTo('auditor@example.test');

        $share->forceFill(['code_expires_at' => now()->subMinute()])->save();

        $this->post(route('documents.shared.verify-code', $token), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_codes_cannot_be_sent_on_demand(): void
    {
        /*
         * Without a cooldown the send button is an open relay: anybody holding the link can post to
         * it repeatedly and fill the recipient's inbox, and the recipient cannot tell which of the
         * twenty codes is live.
         */
        Notification::fake();

        ['token' => $token] = $this->share();

        $this->post(route('documents.shared.send-code', $token))->assertSessionHasNoErrors();
        $this->post(route('documents.shared.send-code', $token))->assertSessionHasErrors('code');

        Notification::assertSentOnDemandTimes(DocumentShareCodeNotification::class, 1);
    }

    public function test_asking_for_a_new_code_gives_back_the_attempts(): void
    {
        // Somebody who mistyped twice and asked for a fresh code has not used up their attempts on
        // a code that no longer exists.
        Notification::fake();

        ['share' => $share, 'token' => $token] = $this->share();
        $this->post(route('documents.shared.send-code', $token));
        $this->post(route('documents.shared.verify-code', $token), ['code' => '000000']);

        $this->assertSame(1, (int) $share->fresh()->code_attempts);

        // Past the cooldown, which is about pestering the recipient rather than about attempts.
        $share->forceFill(['code_sent_at' => now()->subMinutes(5)])->save();
        $this->post(route('documents.shared.send-code', $token));

        $this->assertSame(0, (int) $share->fresh()->code_attempts);
    }

    // ── Where it does not apply ──────────────────────────────────────────────

    public function test_a_share_without_a_recipient_cannot_require_a_code(): void
    {
        /*
         * There would be nowhere to send it, and the link would be one nobody could ever open —
         * including the person it was made for.
         */
        ['share' => $share, 'token' => $token] = $this->share([
            'recipient_email' => null,
            'require_email_code' => true,
        ]);

        $this->assertFalse($share->fresh()->needsEmailCode());
        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locked', false));
    }

    public function test_a_share_that_was_not_asked_to_verify_opens_as_before(): void
    {
        ['token' => $token] = $this->share(['require_email_code' => false]);

        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locked', false));
    }

    public function test_the_password_is_answered_before_a_code_is_sent(): void
    {
        /*
         * Sending an email the moment anybody loads the page would turn a link that has gone astray
         * into a way of pestering the person it was meant for.
         */
        Notification::fake();

        ['token' => $token] = $this->share(['password' => 'correct horse']);

        $this->get(route('documents.shared.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('gate', 'password'));

        Notification::assertNothingSent();
    }
}
