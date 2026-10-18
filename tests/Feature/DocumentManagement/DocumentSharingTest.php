<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Operations\DocumentManagement\Models\DocumentShareAccess;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\LegalHoldService;
use App\Modules\Operations\DocumentManagement\Services\ShareService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Letting a document out of the building.
 *
 * The only route in this module that serves a file to a request with no session, so these tests are
 * mostly about what it refuses. The failures worth preventing are all one shape: a link that keeps
 * working after somebody decided it should not.
 */
class DocumentSharingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $user;

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

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'share+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);
    }

    private function shares(): ShareService
    {
        return app(ShareService::class);
    }

    /** A document with an actual file behind it, so the streaming routes have bytes to serve. */
    private function document(array $attributes = []): Document
    {
        Storage::fake('documents');

        $document = Document::create(array_merge([
            'title' => 'Audited financial statements',
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));

        $path = 'shared/' . Str::random(12) . '.pdf';
        Storage::disk('documents')->put($path, '%PDF-1.4 pretend');

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'statements.pdf', 'stored_name' => basename($path), 'extension' => 'pdf',
            'mime' => 'application/pdf', 'size' => 16,
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    // ── Issuing ──────────────────────────────────────────────────────────────

    public function test_the_link_is_returned_once_and_never_stored_in_a_readable_form(): void
    {
        $result = $this->shares()->create($this->document());

        $this->assertNotEmpty($result['token']);
        $this->assertDatabaseMissing('document_shares', ['token_hash' => $result['token']]);
        $this->assertDatabaseHas('document_shares', ['token_hash' => hash('sha256', $result['token'])]);
    }

    public function test_a_lifetime_beyond_what_the_company_allows_is_clamped_rather_than_honoured(): void
    {
        DocumentSetting::query()->delete();
        DocumentSetting::create(['share_max_days' => 7, 'sharing_enabled' => true]);

        $result = $this->shares()->create($this->document(), ['expires_days' => 365]);

        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $result['share']->expires_at->toDateString(),
            'A ceiling somebody can talk their way past is not a ceiling.',
        );
    }

    public function test_issuing_a_link_is_written_into_the_document_history(): void
    {
        $document = $this->document();
        $this->shares()->create($document, ['recipient_email' => 'auditor@example.test']);

        $event = DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::SHARED)->first();

        $this->assertNotNull($event);
        $this->assertSame('auditor@example.test', $event->detail['recipient']);
    }

    // ── Opening ──────────────────────────────────────────────────────────────

    public function test_a_valid_link_opens_without_a_session(): void
    {
        $result = $this->shares()->create($this->document());

        // The whole point: no acting-as, no session, no account.
        auth()->logout();

        $this->get(route('documents.shared.show', $result['token']))->assertOk();
    }

    public function test_the_file_is_served_with_the_headers_that_stop_it_executing(): void
    {
        $result = $this->shares()->create($this->document(), ['allow_download' => true]);
        auth()->logout();

        $response = $this->get(route('documents.shared.view', $result['token']));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // The directives, not the exact string: Symfony normalises and reorders this header, and a
        // test pinned to its spelling breaks on an upgrade without anything being wrong.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    // ── Refusing ─────────────────────────────────────────────────────────────

    public function test_an_unknown_expired_revoked_and_exhausted_link_are_indistinguishable(): void
    {
        /*
         * All four must answer identically. A different status for "expired" tells somebody working
         * through guesses that the guess was a real token once, which is the one thing a wrong guess
         * must never confirm.
         */
        $unknown = str_repeat('a', 64);

        $expired = $this->shares()->create($this->document());
        $expired['share']->forceFill(['expires_at' => now()->subDay()])->save();

        $revoked = $this->shares()->create($this->document());
        $this->shares()->revoke($revoked['share']);

        $exhausted = $this->shares()->create($this->document(), ['allow_download' => true, 'max_downloads' => 1]);
        $exhausted['share']->forceFill(['download_count' => 1])->save();

        auth()->logout();

        foreach ([$unknown, $expired['token'], $revoked['token'], $exhausted['token']] as $token) {
            $this->get(route('documents.shared.show', $token))->assertNotFound();
        }
    }

    public function test_a_view_only_link_does_not_serve_a_download(): void
    {
        $result = $this->shares()->create($this->document(), ['allow_download' => false]);
        auth()->logout();

        $this->get(route('documents.shared.view', $result['token']))->assertOk();
        $this->get(route('documents.shared.download', $result['token']))->assertNotFound();
    }

    public function test_a_password_protected_link_discloses_nothing_before_the_password(): void
    {
        $document = $this->document(['title' => 'Disciplinary hearing — J. Dlamini']);
        $result = $this->shares()->create($document, ['password' => 'correct-horse']);
        auth()->logout();

        $response = $this->get(route('documents.shared.show', $result['token']));

        $response->assertOk();
        // The title is often the sensitive part; it must not be in the payload behind the prompt.
        $this->assertStringNotContainsString('Dlamini', $response->getContent());

        $this->get(route('documents.shared.view', $result['token']))->assertNotFound();
    }

    public function test_the_right_password_opens_it_and_a_wrong_one_does_not(): void
    {
        $result = $this->shares()->create($this->document(), ['password' => 'correct-horse']);
        auth()->logout();

        $this->post(route('documents.shared.unlock', $result['token']), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->post(route('documents.shared.unlock', $result['token']), ['password' => 'correct-horse'])
            ->assertRedirect(route('documents.shared.show', $result['token']));

        $this->get(route('documents.shared.view', $result['token']))->assertOk();
    }

    public function test_a_document_put_under_legal_hold_stops_being_served_by_a_link_issued_earlier(): void
    {
        // The share was a decision about the document as it stood then. A link issued last month
        // must not keep handing it out after the organisation decided to preserve it.
        $document = $this->document();
        $result = $this->shares()->create($document);

        app(LegalHoldService::class)->place($document, 'CASE-2026-014');

        auth()->logout();

        $this->get(route('documents.shared.show', $result['token']))->assertNotFound();
        $this->get(route('documents.shared.view', $result['token']))->assertNotFound();
    }

    public function test_a_deleted_document_stops_being_served(): void
    {
        $document = $this->document();
        $result = $this->shares()->create($document);

        $document->delete();

        auth()->logout();

        $this->get(route('documents.shared.show', $result['token']))->assertNotFound();
    }

    public function test_a_download_limit_is_spent_by_downloads_and_not_by_views(): void
    {
        // A limit decremented on every page render is spent by the recipient's own browser
        // prefetching the link before they have read anything.
        $result = $this->shares()->create($this->document(), ['allow_download' => true, 'max_downloads' => 2]);
        $token = $result['token'];

        auth()->logout();

        $this->get(route('documents.shared.show', $token))->assertOk();
        $this->get(route('documents.shared.show', $token))->assertOk();
        $this->assertSame(0, $result['share']->fresh()->download_count);

        $this->get(route('documents.shared.download', $token))->assertOk();
        $this->get(route('documents.shared.download', $token))->assertOk();

        $this->assertSame(2, $result['share']->fresh()->download_count);
        $this->get(route('documents.shared.download', $token))->assertNotFound();
    }

    // ── Recording ────────────────────────────────────────────────────────────

    public function test_every_opening_is_logged_with_where_it_came_from(): void
    {
        $result = $this->shares()->create($this->document(), ['allow_download' => true]);
        auth()->logout();

        $this->get(route('documents.shared.show', $result['token']));
        $this->get(route('documents.shared.download', $result['token']));

        $accesses = DocumentShareAccess::where('share_id', $result['share']->id)->get();

        $this->assertCount(2, $accesses, 'A counter cannot say when a document left or where it went.');
        $this->assertTrue($accesses->pluck('action')->contains(DocumentShareAccess::VIEWED));
        $this->assertTrue($accesses->pluck('action')->contains(DocumentShareAccess::DOWNLOADED));
        $this->assertNotNull($accesses->first()->ip);
    }

    public function test_revoking_keeps_the_record_that_the_document_was_sent_out(): void
    {
        $document = $this->document();
        $result = $this->shares()->create($document, ['recipient_email' => 'auditor@example.test']);

        $this->shares()->revoke($result['share']);

        $stored = DocumentShare::find($result['share']->id);

        $this->assertNotNull($stored, '"Was this sent outside, and to whom" is asked long after the link stops working.');
        $this->assertNotNull($stored->revoked_at);
        $this->assertSame('auditor@example.test', $stored->recipient_email);
        $this->assertSame('revoked', $stored->state());
    }

    public function test_every_live_link_for_a_document_can_be_withdrawn_at_once(): void
    {
        $document = $this->document();
        $this->shares()->create($document);
        $this->shares()->create($document);
        $other = $this->shares()->create($this->document());

        $revoked = $this->shares()->revokeAllFor($document);

        $this->assertSame(2, $revoked);
        $this->assertSame(0, DocumentShare::where('document_id', $document->id)->active()->count());
        $this->assertTrue($other['share']->fresh()->isUsable(), 'Another document\'s links are not this one\'s to withdraw.');
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_opening_a_link_pins_the_tenant_rather_than_running_unscoped(): void
    {
        /*
         * The failure this prevents does not look like a failure. With no company set, row-level
         * security reads the request as unrestricted, and every query after the token lookup can
         * see every organisation's rows — so one wrong join serves the wrong tenant's document and
         * nothing errors.
         */
        $result = $this->shares()->create($this->document());

        auth()->logout();
        DB::statement("SELECT set_config('app.company_id', '', false)");

        $this->get(route('documents.shared.show', $result['token']))->assertOk();

        $this->assertSame(
            (string) $this->companyId,
            DB::selectOne("SELECT current_setting('app.company_id', true) AS c")->c,
            'The token decides the tenant; nothing else on a public request is trusted to.',
        );
    }

    // ── The two gates in front of issuing ────────────────────────────────────

    public function test_the_route_refuses_while_sharing_is_switched_off_for_the_company(): void
    {
        // The master switch, which starts off. A repository does not acquire the ability to publish
        // its contents on the day it is upgraded.
        DocumentSetting::query()->delete();
        DocumentSetting::create(['sharing_enabled' => false, 'share_max_days' => 30]);

        $this->entitleAndPermit('document.view', 'document.share');

        $document = $this->document();

        $this->post(route('document-management.shares.store', $document->uuid), [])
            ->assertRedirect();

        $this->assertSame(0, DocumentShare::where('document_id', $document->id)->count());
    }

    public function test_reading_a_document_does_not_carry_the_right_to_publish_it(): void
    {
        DocumentSetting::query()->delete();
        DocumentSetting::create(['sharing_enabled' => true, 'share_max_days' => 30]);

        // Everything except document.share. Downloading gives a copy to somebody with an account
        // here; sharing gives one to somebody without.
        $this->entitleAndPermit('document.view', 'document.download');

        $document = $this->document();

        $this->post(route('document-management.shares.store', $document->uuid), [])
            ->assertForbidden();

        $this->assertSame(0, DocumentShare::where('document_id', $document->id)->count());
    }

    public function test_deleting_a_document_withdraws_its_live_links(): void
    {
        $document = $this->document();
        $result = $this->shares()->create($document);

        app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->delete($document);

        $this->assertSame(
            'revoked',
            $result['share']->fresh()->state(),
            'A link left showing as active for a document that no longer exists is a question somebody has to chase.',
        );
    }

    /** Entitles the company to the module and gives the acting user exactly these permissions. */
    private function entitleAndPermit(string ...$permissions): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
        }

        $this->user->givePermissionTo($permissions);
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
    }
}
