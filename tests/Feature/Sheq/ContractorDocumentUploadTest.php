<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Support\UploadedArchive;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * A contractor document is the file, not a row saying a file exists.
 *
 * The dialog offered no file field at all, so a "document" was a type and a date with nothing
 * behind it — a compliance file that proves nothing to an inspector. Only one could be added at a
 * time, when a contractor's safety file arrives as a dozen, and a zip had to be unpacked by hand
 * before any of it could be uploaded.
 */
class ContractorDocumentUploadTest extends TestCase
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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'doc-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $this->user->givePermissionTo('sheq.contractor.manage');
        $this->user->forgetCachedPermissions();
    }

    private function contractor(): array
    {
        return app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);
    }

    private function storedFilesFor(array $documentIds): int
    {
        return DB::table('sheq_files')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'CONTRACTOR_DOCUMENT')
            ->whereIn('subject_id', $documentIds)
            ->count();
    }

    private function documentIds(int $contractorId): array
    {
        return DB::table('sheq_contractor_documents')
            ->where('contractor_id', $contractorId)->pluck('id')->all();
    }

    /** A zip holding the named files, as a contractor's safety file arrives. */
    private function zipOf(array $names): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-zip-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($names as $name) {
            // Real PDF bytes: the file service detects the type from content, not the name, so a
            // zip entry has to actually be what it claims.
            $zip->addFromString($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        }

        $zip->close();

        return new UploadedFile($path, 'safety-file.zip', 'application/zip', null, true);
    }

    public function test_the_file_itself_is_stored_in_the_database(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'LETTER_OF_GOOD_STANDING',
                'files' => [UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $ids = $this->documentIds($contractor['id']);

        $this->assertCount(1, $ids);
        $this->assertSame(1, $this->storedFilesFor($ids),
            'A document row with no file behind it proves nothing to an inspector.');

        /*
         * Stored in Postgres rather than on a disk somebody has to back up separately.
         *
         * Asserted through the `documents` disk, which is configured `driver: database` — the
         * bytes go to the platform's shared store and `sheq_files` keeps the path to them. An
         * earlier version of this test asserted `sheq_files.data`, which `store()` does not
         * populate; it failed while the file was in fact safely in the database.
         */
        $path = DB::table('sheq_files')->where('subject_id', $ids[0])->value('storage_path');

        $this->assertNotNull($path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('documents')->exists($path),
            'The file is not retrievable from the database-backed disk.');
    }

    public function test_several_files_can_be_attached_at_once(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'SAFETY_FILE',
                'files' => [
                    UploadedFile::fake()->create('method-statement.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('insurance.pdf', 10, 'application/pdf'),
                ],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(2, $this->documentIds($contractor['id']));
    }

    public function test_a_zip_becomes_one_document_per_file_inside_it(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'SAFETY_FILE',
                'files' => [$this->zipOf(['good-standing.pdf', 'insurance.pdf', 'section-37.pdf'])],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $ids = $this->documentIds($contractor['id']);

        $this->assertCount(3, $ids);
        $this->assertSame(3, $this->storedFilesFor($ids));

        // Each is titled by its own name; eight identically named rows would be worse than the zip.
        $titles = DB::table('sheq_contractor_documents')->whereIn('id', $ids)->pluck('title')->all();
        $this->assertContains('insurance.pdf', $titles);
    }

    public function test_a_path_in_an_archive_entry_cannot_escape(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evil-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../../../../etc/passwd', 'root:x:0:0');
        $zip->close();

        $expanded = UploadedArchive::expand(
            new UploadedFile($path, 'evil.zip', 'application/zip', null, true));

        // Only the basename survives, so the path is discarded rather than honoured.
        $this->assertSame('passwd', $expanded[0]['name']);
    }

    public function test_an_archive_with_too_many_entries_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'many-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        for ($i = 0; $i < 250; $i++) {
            $zip->addFromString("file-{$i}.txt", 'x');
        }

        $zip->close();

        $this->expectExceptionMessageMatches('/can be unpacked at once/');

        UploadedArchive::expand(new UploadedFile($path, 'many.zip', 'application/zip', null, true));
    }

    public function test_a_document_with_no_file_is_still_allowed(): void
    {
        $contractor = $this->contractor();

        // A type and an expiry is a legitimate placeholder for a certificate somebody is waiting
        // on — and it still blocks access until the certificate arrives.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'PUBLIC_LIABILITY_INSURANCE',
                'expires_on' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->documentIds($contractor['id']));
    }
}
