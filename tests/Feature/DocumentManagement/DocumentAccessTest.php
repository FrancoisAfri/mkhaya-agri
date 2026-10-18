<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reaching documents through the application, and what the responses carry.
 *
 * Serving a file is the part worth testing hardest. A document served inline from the application's
 * own domain is same-origin with the application, so an uploaded file a browser will execute is
 * stored cross-site scripting. The upload guard is the first line against that; these headers are
 * the second, because one of the two will eventually be wrong.
 */
class DocumentAccessTest extends TestCase
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

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'dma+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]));
    }

    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\ncontent\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function document(array $attributes = []): Document
    {
        return app(DocumentService::class)->store($this->pdf(), $attributes)['document'];
    }

    // ── The screens ──────────────────────────────────────────────────────────

    public function test_the_library_lists_documents_with_what_the_screen_needs(): void
    {
        $this->document(['description' => 'Board pack']);

        $this->get(route('document-management.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/DocumentManagement/Pages/Index')
                ->has('documents.data', 1)
                ->has('settings.allowed_extensions')
                ->has('totals'));
    }

    public function test_search_matches_the_description_as_well_as_the_title(): void
    {
        $this->document(['title' => 'Untitled', 'description' => 'quarterly board pack']);
        $this->document(['title' => 'Something else']);

        $this->get(route('document-management.index', ['q' => 'quarterly']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1));
    }

    public function test_opening_a_document_records_that_it_was_viewed(): void
    {
        $document = $this->document();

        $this->get(route('document-management.documents.show', $document->uuid))->assertOk();

        // "Who has opened this?" is the first question asked of a confidential document, and it
        // cannot be answered later if nobody wrote it down at the time.
        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::VIEWED)->count());
    }

    // ── Serving bytes ────────────────────────────────────────────────────────

    public function test_a_download_is_an_attachment_and_never_sniffed(): void
    {
        $document = $this->document();

        $response = $this->get(route('document-management.documents.download', $document->uuid));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        // Never the file's own type on a download: a browser given a type it recognises is a
        // browser that may decide to render it.
        $response->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_a_preview_is_inline_but_still_locked_down(): void
    {
        $document = $this->document();

        $response = $this->get(route('document-management.documents.preview', $document->uuid));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_private_document_is_never_left_in_a_shared_cache(): void
    {
        $document = $this->document(['is_confidential' => true]);

        $cacheControl = $this->get(route('document-management.documents.download', $document->uuid))
            ->headers->get('Cache-Control');

        // Asserted by directive rather than by string: the framework normalises the order and adds
        // must-revalidate and no-cache of its own, which is stricter than what was asked for.
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    public function test_a_dangerous_filename_cannot_escape_the_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\nx\n%%EOF");
        $document = app(DocumentService::class)->store(
            new UploadedFile($path, "evil\"; filename=\"passwd.pdf", 'application/pdf', null, true),
        )['document'];

        $disposition = $this->get(route('document-management.documents.download', $document->uuid))
            ->headers->get('Content-Disposition');

        // A filename carrying quotes or newlines could otherwise close the header and set ones we
        // did not intend.
        $this->assertSame(1, substr_count($disposition, 'filename='));
        $this->assertStringNotContainsString('"; filename="passwd', $disposition);
    }

    public function test_a_type_that_cannot_be_previewed_is_refused_rather_than_guessed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, "PK\x03\x04" . str_repeat('x', 64));
        $document = app(DocumentService::class)->store(
            new UploadedFile($path, 'bundle.zip', 'application/zip', null, true),
        )['document'];

        $this->get(route('document-management.documents.preview', $document->uuid))->assertStatus(415);
    }

    public function test_a_version_belonging_to_another_document_is_not_served(): void
    {
        $mine = $this->document();
        $other = $this->document();

        // Two valid ids in one URL is the shape people try first.
        $this->get(route('document-management.documents.download', [
            'document' => $mine->uuid, 'version' => $other->currentVersion->uuid,
        ]))->assertNotFound();
    }

    // ── Actions through the screens ──────────────────────────────────────────

    public function test_a_document_can_be_archived_and_restored_from_the_screen(): void
    {
        $document = $this->document();

        $this->post(route('document-management.documents.archive', $document->uuid), ['reason' => 'Superseded'])
            ->assertRedirect();
        $this->assertTrue($document->fresh()->isArchived());

        $this->post(route('document-management.documents.restore', $document->uuid))->assertRedirect();
        $this->assertFalse($document->fresh()->isArchived());
    }

    public function test_the_screen_refuses_to_destroy_a_document_under_hold(): void
    {
        $document = $this->document(['is_legally_sensitive' => true]);

        $this->delete(route('document-management.documents.purge', $document->uuid))
            ->assertSessionHas('error');

        $this->assertNotNull(Document::find($document->id));
    }

    public function test_uploading_several_files_keeps_the_good_ones_when_one_is_refused(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($bad, '<?php echo 1;');

        $this->post(route('document-management.documents.store'), [
            'files' => [
                $this->pdf('one.pdf'),
                new UploadedFile($bad, 'shell.php', 'text/plain', null, true),
                $this->pdf('two.pdf'),
            ],
        ])->assertRedirect()->assertSessionHas('warning');

        // Losing a nine-file upload to one bad file is how people learn to hate a document system.
        $this->assertSame(2, Document::count());
    }
}
