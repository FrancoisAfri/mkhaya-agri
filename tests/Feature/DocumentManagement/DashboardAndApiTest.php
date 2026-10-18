<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFavourite;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentDashboard;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The dashboard, the personal slices, and the API.
 *
 * The thread running through all three is the same: every count, list and endpoint answers through
 * the visibility scope. A dashboard total of "1,482 documents" shown to somebody who can open four
 * hundred discloses how much they are not being shown, and an API that read documents directly
 * would be where the confidentiality rules were eventually forgotten.
 */
class DashboardAndApiTest extends TestCase
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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();

        foreach (['document.view', 'document.upload', 'document.download', 'document.update', 'document.settings.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'dash+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->user->givePermissionTo(['document.view', 'document.upload', 'document.download', 'document.update']);
        $this->user = $this->user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $this->user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        $this->actingAs($this->user);
        Storage::fake('documents');
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    private function withFile(Document $document): Document
    {
        $path = 'api/' . Str::random(10) . '.txt';
        Storage::disk('documents')->put($path, 'File contents here.');

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'file.txt', 'stored_name' => basename($path),
            'extension' => 'txt', 'mime' => 'text/plain', 'size' => 19,
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    // ── §22 Dashboard ────────────────────────────────────────────────────────

    public function test_the_dashboard_counts_what_it_says_it_counts(): void
    {
        $this->document(['title' => 'Active one']);
        $this->document(['title' => 'Archived one', 'status' => Document::STATUS_ARCHIVED]);
        $this->document(['title' => 'Expiring', 'expiry_date' => now()->addDays(10)]);
        $this->document(['title' => 'Lapsed', 'expiry_date' => now()->subDays(3)]);

        $stats = app(DocumentDashboard::class)->for($this->user);

        $this->assertSame(3, $stats['overview']['total'], 'The total is active documents, not everything ever filed.');
        $this->assertSame(1, $stats['overview']['archived']);
        $this->assertSame(1, $stats['alerts']['expired']);

        $within30 = collect($stats['alerts']['windows'])->firstWhere('days', 30);
        $this->assertSame(1, $within30['count']);
    }

    public function test_the_dashboard_does_not_count_documents_the_viewer_cannot_open(): void
    {
        /*
         * A total that includes what somebody is refused tells them exactly how much is being kept
         * from them, which is the thing the confidentiality flag exists to avoid.
         */
        $this->document(['title' => 'Ordinary']);
        $this->document(['title' => 'Secret', 'is_confidential' => true]);

        $stats = app(DocumentDashboard::class)->for($this->user);

        $this->assertSame(1, $stats['overview']['total']);
    }

    public function test_the_dashboard_page_loads(): void
    {
        $this->get(route('document-management.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/DocumentManagement/Pages/Dashboard'));
    }

    // ── §24 Storage ──────────────────────────────────────────────────────────

    public function test_storage_reports_a_breakdown_and_the_largest_files(): void
    {
        $dashboard = app(DocumentDashboard::class);

        $stats = $dashboard->for($this->user);

        $this->assertArrayHasKey('used', $stats['storage']);
        $this->assertArrayHasKey('breakdown', $stats['storage']);
        $this->assertIsArray($dashboard->largestFiles(5));
    }

    public function test_storage_is_a_settings_screen_rather_than_a_document_one(): void
    {
        // It says nothing about any particular document and everything about the account.
        $this->get(route('document-management.storage'))->assertForbidden();

        $this->user->givePermissionTo('document.settings.manage');
        $this->actingAs($this->user->fresh());

        $this->get(route('document-management.storage'))->assertOk();
    }

    // ── §29 The personal slices ──────────────────────────────────────────────

    public function test_my_documents_shows_only_what_this_person_uploaded(): void
    {
        $other = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'other+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        // created_by is set by the upload service rather than the model, so a document made
        // directly in a test has none unless it says so.
        $mine = $this->document(['title' => 'Mine', 'created_by' => $this->user->id]);
        $this->document(['title' => 'Theirs', 'created_by' => $other->id]);

        $found = app(DocumentSearch::class)->query($this->user, ['mine' => $this->user->id])->pluck('title');

        $this->assertTrue($found->contains('Mine'));
        $this->assertFalse($found->contains('Theirs'));
    }

    public function test_favourites_are_private_to_the_person_who_starred_them(): void
    {
        // One person starring the leave policy must not put it on everybody's list.
        $document = $this->document(['title' => 'Starred']);

        $this->post(route('document-management.documents.favourite', $document->uuid))->assertRedirect();

        $mine = app(DocumentSearch::class)->query($this->user, ['favourites' => $this->user->id])->pluck('title');
        $this->assertTrue($mine->contains('Starred'));

        $somebodyElse = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'else+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        $theirs = app(DocumentSearch::class)->query($somebodyElse, ['favourites' => $somebodyElse->id])->pluck('title');
        $this->assertFalse($theirs->contains('Starred'));
    }

    public function test_starring_twice_removes_the_star(): void
    {
        $document = $this->document();

        $this->post(route('document-management.documents.favourite', $document->uuid));
        $this->post(route('document-management.documents.favourite', $document->uuid));

        $this->assertSame(0, DocumentFavourite::where('document_id', $document->id)->count());
    }

    // ── §27 API ──────────────────────────────────────────────────────────────

    public function test_the_api_lists_documents_through_the_same_visibility_rule(): void
    {
        $this->document(['title' => 'Ordinary']);
        $this->document(['title' => 'Secret', 'is_confidential' => true]);

        $response = $this->getJson('/api/document-management/documents');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('Ordinary'));
        $this->assertFalse($titles->contains('Secret'), 'An API that read documents directly is where the rules get forgotten.');
    }

    public function test_the_api_refuses_a_document_this_person_may_not_see(): void
    {
        $secret = $this->document(['title' => 'Secret', 'is_confidential' => true]);

        $this->getJson('/api/document-management/documents/' . $secret->uuid)->assertForbidden();
    }

    public function test_the_api_records_a_read_in_the_trail(): void
    {
        // An API read is still somebody reading the document, and a trail that only knows about
        // browsers is a trail with a hole in it.
        $document = $this->document();

        $this->getJson('/api/document-management/documents/' . $document->uuid)->assertOk();

        $event = \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
            ->where('document_id', $document->id)
            ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::VIEWED)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('api', $event->detail['via'] ?? null);
    }

    public function test_the_api_can_set_an_expiry_date(): void
    {
        $document = $this->document();

        $this->putJson('/api/document-management/documents/' . $document->uuid . '/expiry', [
            'expiry_date' => '2027-06-30',
            'blocks_when_expired' => true,
        ])->assertOk();

        $document = $document->fresh();

        $this->assertSame('2027-06-30', $document->expiry_date?->toDateString());
        $this->assertTrue((bool) $document->blocks_when_expired);
    }

    public function test_the_api_serves_bytes_with_the_same_headers_as_the_browser(): void
    {
        $document = $this->withFile($this->document());

        $response = $this->get('/api/document-management/documents/' . $document->uuid . '/download');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_api_is_closed_to_a_company_without_the_module(): void
    {
        /*
         * The gate that matters most for an API: a company that has not bought Document Management
         * must not be able to reach it with a token because the middleware was only put on the web
         * routes.
         */
        DB::table('security_modules')->where('code_name', 'document_management')->update(['active' => 0]);
        Cache::flush();

        $this->getJson('/api/document-management/documents')->assertForbidden();
    }

    public function test_the_api_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/document-management/documents')->assertUnauthorized();
    }

    // ── §25 Malware scanning, where the server supports it ───────────────────

    public function test_an_upload_records_whether_it_was_scanned_at_all(): void
    {
        /*
         * "No infection found" and "nothing looked" produce the same absence of a warning. An
         * organisation asked whether its repository is scanned deserves a better answer than the
         * silence of a feature that was never installed, so the verdict — including "unavailable" —
         * is written against the version.
         */
        $scanner = app(\App\Modules\Operations\DocumentManagement\Services\MalwareScanner::class);

        $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->store(
            \Illuminate\Http\UploadedFile::fake()->createWithContent('notes.txt', 'Harmless.'),
            ['title' => 'Scanned upload'],
        );

        $this->assertNotNull($result['document'], $result['error'] ?? '');

        $version = $result['document']->fresh()->currentVersion;

        $this->assertNotNull($version->scanned_at, 'Whether it was scanned is itself a fact worth recording.');
        $this->assertSame(
            $scanner->available()
                ? \App\Modules\Operations\DocumentManagement\Services\MalwareScanner::CLEAN
                : \App\Modules\Operations\DocumentManagement\Services\MalwareScanner::UNAVAILABLE,
            $version->scan_status,
        );
    }

    public function test_an_upload_is_not_refused_merely_because_no_scanner_is_installed(): void
    {
        // A repository that rejected every upload on a server without ClamAV would be unusable, and
        // most installations of this platform will not have one.
        $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->store(
            \Illuminate\Http\UploadedFile::fake()->createWithContent('ok.txt', 'Fine.'),
            ['title' => 'Unscanned but allowed'],
        );

        $this->assertNotNull($result['document']);
        $this->assertNull($result['error']);
    }

    public function test_the_scanner_reports_unavailable_rather_than_clean_when_nothing_is_installed(): void
    {
        $scanner = app(\App\Modules\Operations\DocumentManagement\Services\MalwareScanner::class);

        if ($scanner->available()) {
            $this->markTestSkipped('A scanner is installed on this machine, so the absent case cannot be exercised.');
        }

        $verdict = $scanner->scan(\Illuminate\Http\UploadedFile::fake()->createWithContent('x.txt', 'x'));

        $this->assertSame(
            \App\Modules\Operations\DocumentManagement\Services\MalwareScanner::UNAVAILABLE,
            $verdict['status'],
            'Reporting clean because nothing looked is a false assurance somebody will rely on.',
        );
    }
}
