<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\ArchiveImporter;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Uploading a folder, by uploading a zip of it.
 *
 * A browser cannot hand over a directory in a form post, so zipping the folder is how people
 * already move a filing cabinet. The structure inside is rebuilt rather than flattened — a year's
 * correspondence arriving as one heap of four hundred documents is not the same as arriving as the
 * folders it was filed in.
 *
 * A zip is a container somebody else built, so most of what is tested here is what happens when
 * that somebody was not friendly.
 */
class ArchiveImportTest extends TestCase
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

        /*
         * The module has to be active before any of its routes answer.
         *
         * Without this the request is redirected to the dashboard with "not currently active on
         * your subscription" and no error bag — which reads as "the endpoint did nothing" rather
         * than "you were never let in", and cost a while to see.
         */
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId([
                'name' => 'Test', 'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now()],
        );

        Cache::flush();

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'zip+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]));
    }

    /** A real PDF, so the magic-number check has something honest to read. */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    /**
     * @param  array<string, string>  $entries  path inside the archive => contents
     */
    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ziptest') . '.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'folder.zip', 'application/zip', null, true);
    }

    private function importer(): ArchiveImporter
    {
        return app(ArchiveImporter::class);
    }

    // ── The point of it ──────────────────────────────────────────────────────

    public function test_a_zip_of_files_becomes_documents(): void
    {
        $result = $this->importer()->import($this->zip([
            'first.pdf' => $this->pdfBytes(),
            'second.pdf' => $this->pdfBytes(),
        ]), null);

        $this->assertSame(2, $result['imported'], 'Not every file in the archive was filed.');
        $this->assertSame([], $result['skipped']);
    }

    /**
     * The structure is rebuilt, not flattened. Four hundred documents in one heap is a different
     * thing from four hundred documents in the folders somebody filed them in.
     */
    public function test_the_folder_structure_inside_is_rebuilt(): void
    {
        $result = $this->importer()->import($this->zip([
            'Correspondence/2026/january.pdf' => $this->pdfBytes(),
            'Correspondence/2026/february.pdf' => $this->pdfBytes(),
            'Drawings/site-plan.pdf' => $this->pdfBytes(),
        ]), null);

        $this->assertSame(3, $result['imported']);

        $correspondence = DocumentFolder::where('name', 'Correspondence')->whereNull('parent_id')->first();
        $this->assertNotNull($correspondence, 'The top folder in the archive was not created.');

        $year = DocumentFolder::where('name', '2026')->where('parent_id', $correspondence->id)->first();
        $this->assertNotNull($year, 'The nested folder was flattened away.');

        $this->assertSame(2, Document::where('folder_id', $year->id)->count());
    }

    /** Four hundred files in one folder must create that folder once, not four hundred times. */
    public function test_a_folder_is_created_once_however_many_files_are_in_it(): void
    {
        $this->importer()->import($this->zip([
            'Reports/a.pdf' => $this->pdfBytes(),
            'Reports/b.pdf' => $this->pdfBytes(),
            'Reports/c.pdf' => $this->pdfBytes(),
        ]), null);

        $this->assertSame(1, DocumentFolder::where('name', 'Reports')->whereNull('deleted_at')->count());
    }

    public function test_files_land_inside_the_folder_the_upload_targeted(): void
    {
        $into = app(\App\Modules\Operations\DocumentManagement\Services\FolderService::class)
            ->create(['name' => 'Target ' . Str::random(5)]);

        $this->importer()->import($this->zip([
            'Sub/inside.pdf' => $this->pdfBytes(),
        ]), $into->id);

        $sub = DocumentFolder::where('name', 'Sub')->where('parent_id', $into->id)->first();

        $this->assertNotNull($sub, 'The archive was not expanded under the folder it was dropped on.');
    }

    // ── The archive as hostile input ─────────────────────────────────────────

    /**
     * Zip-slip, the oldest trick there is. Nothing here joins an archive path onto a filesystem
     * path, but an entry called `..` would still become a folder called `..` — a trap for whatever
     * reads it next.
     */
    public function test_an_entry_that_climbs_out_of_the_archive_is_refused(): void
    {
        $result = $this->importer()->import($this->zip([
            '../../../etc/passwd.pdf' => $this->pdfBytes(),
            'legitimate.pdf' => $this->pdfBytes(),
        ]), null);

        $this->assertSame(1, $result['imported'], 'A traversing entry was filed.');
        $this->assertNotEmpty($result['skipped']);
        $this->assertStringNotContainsString('passwd', json_encode(
            Document::pluck('title')->all(),
        ));
    }

    /** An archive of a hundred thousand files ties up a request and the audit trail behind it. */
    public function test_an_archive_with_too_many_entries_is_refused(): void
    {
        $entries = [];
        for ($i = 0; $i < 520; $i++) {
            $entries["file{$i}.pdf"] = $this->pdfBytes();
        }

        $this->expectExceptionMessageMatches('/at most/i');

        $this->importer()->import($this->zip($entries), null);
    }

    /**
     * One bad file must not cost the rest. Every entry goes through DocumentService, so a
     * disallowed type is refused there exactly as it would be on a single upload.
     */
    public function test_a_disallowed_file_is_skipped_and_the_rest_still_load(): void
    {
        $result = $this->importer()->import($this->zip([
            'good.pdf' => $this->pdfBytes(),
            'payload.exe' => 'MZ not a document',
        ]), null);

        $this->assertSame(1, $result['imported'], 'A good file was lost because another was refused.');
        // Named, not counted: "1 skipped" does not say which one, or why.
        $this->assertNotEmpty($result['skipped']);
        $this->assertStringContainsString('payload.exe', $result['skipped'][0]['name']);
    }

    /** Files the operating system left behind are not documents anybody filed. */
    public function test_operating_system_clutter_is_ignored(): void
    {
        $result = $this->importer()->import($this->zip([
            'real.pdf' => $this->pdfBytes(),
            '__MACOSX/._real.pdf' => 'rubbish',
            '.DS_Store' => 'rubbish',
            'Thumbs.db' => 'rubbish',
        ]), null);

        $this->assertSame(1, $result['imported']);
        // Silently, not as skips: reporting them would bury the entries somebody cares about.
        $this->assertSame([], $result['skipped']);
    }

    /** Following a zip inside a zip is how one upload becomes unbounded work. */
    public function test_a_nested_zip_is_filed_rather_than_unpacked(): void
    {
        $inner = $this->zip(['deep.pdf' => $this->pdfBytes()]);

        $result = $this->importer()->import($this->zip([
            'bundle.zip' => file_get_contents($inner->getRealPath()),
            'plain.pdf' => $this->pdfBytes(),
        ]), null);

        // Whether the zip itself is an allowed type is the library's decision, not this class's;
        // what matters is that `deep.pdf` never appears as a document of its own.
        $this->assertNull(Document::where('title', 'like', '%deep%')->first(),
            'A zip inside the archive was unpacked.');
        $this->assertGreaterThanOrEqual(1, $result['imported']);
    }

    // ── Through the request ──────────────────────────────────────────────────

    public function test_the_endpoint_expands_an_archive(): void
    {
        $response = $this->post(route('document-management.documents.archive.store'), [
            'archive' => $this->zip(['Via/Http/report.pdf' => $this->pdfBytes()]),
        ]);

        if ($response->status() === 403) {
            $this->markTestSkipped('The document module gate is not open for this user.');
        }

        $response->assertRedirect();
        $this->assertNotNull(DocumentFolder::where('name', 'Http')->first(),
            'The archive did not expand through the request path.');
    }

    /** A refusal somebody can act on, rather than a 500 that says only that something went wrong. */
    public function test_a_file_that_is_not_a_zip_is_refused_with_a_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notzip') . '.zip';
        file_put_contents($path, $this->pdfBytes());

        $response = $this->post(route('document-management.documents.archive.store'), [
            'archive' => new UploadedFile($path, 'folder.zip', 'application/zip', null, true),
        ]);

        if ($response->status() === 403) {
            $this->markTestSkipped('The document module gate is not open for this user.');
        }

        $response->assertSessionHasErrors('archive');
    }
}
