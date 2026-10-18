<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\FileTypeGuard;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Getting files into the repository, and keeping the wrong ones out.
 *
 * An upload endpoint is the most attackable surface in a document module, and nearly every way it
 * goes wrong comes from believing the browser — the filename, the extension and the declared type
 * all arrive from the client and none of them are evidence.
 */
class DocumentUploadTest extends TestCase
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

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'dm+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]));
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    /** A real PDF, so the magic-number check has something honest to read. */
    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function upload(string $name, string $contents, string $declaredMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $declaredMime, null, true);
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_document_is_stored_with_its_first_version(): void
    {
        $result = $this->service()->store($this->pdf(), ['description' => 'Board pack']);

        $this->assertNull($result['error']);
        $document = $result['document'];
        $this->assertSame('report', $document->title);
        $this->assertSame(1, $document->version_count);
        $this->assertNotNull($document->current_version_id);
        $this->assertSame('Board pack', $document->description);
    }

    public function test_the_stored_name_is_generated_and_never_the_uploaded_one(): void
    {
        // A client-supplied filename is the classic route to writing outside the intended
        // directory. The original is kept as a label and only as a label.
        $document = $this->service()->store($this->pdf('../../../etc/passwd.pdf'))['document'];
        $version = $document->currentVersion;

        $this->assertStringNotContainsString('..', $version->stored_name);
        $this->assertStringNotContainsString('/', $version->stored_name);
        $this->assertStringStartsWith('documents/' . $document->uuid . '/', $version->path);
        $this->assertStringContainsString('passwd', $version->original_name);
    }

    public function test_the_upload_is_recorded(): void
    {
        $document = $this->service()->store($this->pdf())['document'];

        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::UPLOADED)->count());
    }

    // ── What must never get in ───────────────────────────────────────────────

    public function test_executable_types_are_refused_whatever_the_settings_say(): void
    {
        DocumentSetting::updateOrCreate([], [
            // Even explicitly allowed, these stay out.
            'allowed_extensions' => ['pdf', 'php', 'html', 'svg', 'exe'],
            'max_file_size_mb' => 25, 'max_files_per_upload' => 20,
        ]);

        foreach (['shell.php' => '<?php echo 1;', 'page.html' => '<script>alert(1)</script>',
                  'logo.svg' => '<svg onload="alert(1)"></svg>'] as $name => $body) {
            $result = $this->service()->store($this->upload($name, $body, 'text/plain'));

            $this->assertNull($result['document'], $name . ' should not have been stored');
            $this->assertStringContainsString('never accepted', (string) $result['error']);
        }
    }

    public function test_a_renamed_executable_is_caught_by_its_contents(): void
    {
        // The extension check passes; the magic number does not. Renaming a file does not change
        // what it is, and a repository that accepts the rename stores an executable called
        // report.pdf.
        $result = $this->service()->store(
            $this->upload('report.pdf', "<?php system(\$_GET['c']); ?>", 'application/pdf'),
        );

        $this->assertNull($result['document']);
        $this->assertStringContainsString('do not match', (string) $result['error']);
    }

    public function test_a_type_outside_the_allowed_list_is_refused_with_a_useful_message(): void
    {
        DocumentSetting::updateOrCreate([], [
            'allowed_extensions' => ['pdf'], 'max_file_size_mb' => 25, 'max_files_per_upload' => 20,
        ]);

        $result = $this->service()->store($this->upload('sheet.csv', "a,b\n1,2", 'text/csv'));

        $this->assertNull($result['document']);
        $this->assertStringContainsString('allowed list', (string) $result['error']);
    }

    public function test_a_file_over_the_configured_limit_is_refused(): void
    {
        DocumentSetting::updateOrCreate([], [
            'max_file_size_mb' => 1, 'max_files_per_upload' => 20,
        ]);

        $big = $this->upload('big.txt', str_repeat('x', 2 * 1024 * 1024), 'text/plain');
        $result = $this->service()->store($big);

        $this->assertNull($result['document']);
        $this->assertStringContainsString('over the 1 MB limit', (string) $result['error']);
    }

    public function test_a_file_with_no_extension_is_refused_rather_than_guessed_at(): void
    {
        $result = $this->service()->store($this->upload('payload', 'anything', 'application/octet-stream'));

        $this->assertNull($result['document']);
        $this->assertStringContainsString('no extension', (string) $result['error']);
    }

    // ── What the guard allows ────────────────────────────────────────────────

    public function test_ordinary_office_documents_are_accepted(): void
    {
        $guard = app(FileTypeGuard::class);
        $allowed = $guard->allowedFor(new DocumentSetting(['max_file_size_mb' => 25]));

        foreach (['pdf', 'docx', 'xlsx', 'pptx', 'csv', 'txt', 'jpg', 'png', 'zip'] as $extension) {
            $this->assertContains($extension, $allowed, ".{$extension} should be allowed by default");
        }
    }

    public function test_the_never_list_cannot_be_switched_on_from_settings(): void
    {
        $guard = app(FileTypeGuard::class);
        $allowed = $guard->allowedFor(new DocumentSetting([
            'allowed_extensions' => ['pdf', 'php', 'js', 'svg', 'exe'],
            'max_file_size_mb' => 25,
        ]));

        foreach (['php', 'js', 'svg', 'exe'] as $extension) {
            $this->assertNotContains($extension, $allowed);
        }
        $this->assertContains('pdf', $allowed);
    }

    public function test_only_types_a_browser_can_show_safely_are_previewable(): void
    {
        $guard = app(FileTypeGuard::class);

        $this->assertTrue($guard->canPreview('application/pdf'));
        $this->assertTrue($guard->canPreview('image/png'));
        // Both carry script and would run same-origin with the application.
        $this->assertFalse($guard->canPreview('image/svg+xml'));
        $this->assertFalse($guard->canPreview('text/html'));
        $this->assertFalse($guard->canPreview('application/zip'));
    }

    // ── Settings that shape the upload ───────────────────────────────────────

    public function test_a_required_description_is_enforced(): void
    {
        DocumentSetting::updateOrCreate([], [
            'require_description' => true, 'max_file_size_mb' => 25, 'max_files_per_upload' => 20,
        ]);

        $this->assertNull($this->service()->store($this->pdf())['document']);
        $this->assertNotNull($this->service()->store($this->pdf(), ['description' => 'Given'])['document']);
    }

    public function test_automatic_naming_applies_the_configured_pattern(): void
    {
        DocumentSetting::updateOrCreate([], [
            'auto_name' => true, 'naming_pattern' => 'DOC-{year}-{original}',
            'max_file_size_mb' => 25, 'max_files_per_upload' => 20,
        ]);

        $document = $this->service()->store($this->pdf('minutes.pdf'))['document'];

        $this->assertSame('DOC-' . date('Y') . '-minutes', $document->title);
    }

    public function test_an_explicit_title_beats_the_naming_pattern(): void
    {
        DocumentSetting::updateOrCreate([], [
            'auto_name' => true, 'naming_pattern' => 'DOC-{seq}',
            'max_file_size_mb' => 25, 'max_files_per_upload' => 20,
        ]);

        $document = $this->service()->store($this->pdf(), ['title' => 'Signed lease'])['document'];

        $this->assertSame('Signed lease', $document->title);
    }
}
