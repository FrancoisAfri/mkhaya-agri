<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\FileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHEQ file storage (SHEQ-OFF-7/8), on the platform's FileStorage trait.
 *
 * Files live in the database as base64, the same way User profiles and Contract Management store
 * theirs. What these tests defend is the part SHEQ adds: an upload that survives a dropped
 * connection, a record that cannot be closed while its evidence is still in flight, and a photograph
 * whose capture time is not destroyed by compression.
 */
class FileStorageTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $incidentId = 90001;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'file-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): FileService
    {
        return app(FileService::class);
    }

    /** A real JPEG, so the trait's GD compression path actually runs. */
    private function photo(int $width = 2400, int $height = 1600): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 40, 40));

        $path = tempnam(sys_get_temp_dir(), 'sheq').'.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return new UploadedFile($path, 'hazard.jpg', 'image/jpeg', null, true);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'subject_type' => 'INCIDENT', 'subject_id' => $this->incidentId,
            'category' => 'EVIDENCE',
        ], $overrides);
    }

    // ── Storage ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_photo_is_stored_in_the_database_not_on_disk(): void
    {
        $result = $this->service()->store($this->companyId, $this->photo(), $this->context(), $this->userId);

        $this->assertSame('STORED', $result['status']);

        $row = DB::table('sheq_files')->where('uuid', $result['uuid'])->first();
        $this->assertSame('image', $row->type);

        /*
         * Still in the database, still not on the server's filesystem — but in tenant_files via the
         * shared disk rather than in this row's own column. That is where encryption at rest and
         * the tenant storage quota apply, which is the reason for the move.
         */
        $this->assertNotNull($row->storage_path, 'The row must point at the stored file.');
        $this->assertTrue(Storage::disk('documents')->exists($row->storage_path));

        // The hash is of the bytes, and stays comparable however they are stored.
        $this->assertSame(hash('sha256', $this->bytesOf($result['uuid'])), $row->sha256);
    }

    public function test_a_large_image_is_compressed_by_the_shared_trait(): void
    {
        // The trait caps the long edge at 1920px; SHEQ does not reimplement that.
        $result = $this->service()->store($this->companyId, $this->photo(2400, 1600),
            $this->context(), $this->userId);

        $size = getimagesizefromstring($this->bytesOf($result['uuid']));

        $this->assertLessThanOrEqual(1920, $size[0], 'The image was not resized.');
    }

    public function test_a_file_type_the_platform_disallows_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sheq').'.exe';
        file_put_contents($path, 'MZ binary');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->store($this->companyId,
            new UploadedFile($path, 'payload.exe', 'application/x-msdownload', null, true),
            $this->context(), $this->userId);
    }

    public function test_an_unknown_subject_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not something a SHEQ file can attach to/');

        $this->service()->store($this->companyId, $this->photo(),
            $this->context(['subject_type' => 'PAYSLIP']), $this->userId);
    }

    public function test_a_retried_upload_is_recognised_rather_than_stored_twice(): void
    {
        // Base64 in a row is expensive; a device retrying after a dropped response must not double it.
        $clientId = (string) Str::uuid();

        $this->service()->store($this->companyId, $this->photo(),
            $this->context(['client_id' => $clientId]), $this->userId);
        $second = $this->service()->store($this->companyId, $this->photo(),
            $this->context(['client_id' => $clientId]), $this->userId);

        $this->assertTrue($second['replayed']);
        $this->assertSame(1, DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('client_id', $clientId)->count());
    }

    // ── Chunked upload (SHEQ-OFF-7) ─────────────────────────────────────────────────────────────

    public function test_a_chunked_upload_assembles_only_when_every_part_has_arrived(): void
    {
        $payload = random_bytes(3000);
        $parts = str_split($payload, 1000);

        $begun = $this->service()->beginUpload($this->companyId, $this->context([
            'original_name' => 'statement.pdf', 'mime' => 'application/pdf',
        ]), count($parts), $this->userId);

        $this->assertSame('PENDING', $begun['status']);

        // Out of order on purpose: a device uploads whatever it can get through.
        foreach ([2, 0] as $i) {
            $result = $this->service()->putChunk($this->companyId, $begun['uuid'], $i, base64_encode($parts[$i]));
            $this->assertSame('PENDING', $result['status'], 'Assembled before all parts arrived.');
        }

        $final = $this->service()->putChunk($this->companyId, $begun['uuid'], 1, base64_encode($parts[1]));

        $this->assertSame('STORED', $final['status']);
        $this->assertSame(strlen($payload), $final['size']);

        $row = DB::table('sheq_files')->where('uuid', $begun['uuid'])->first();
        $this->assertSame($payload, $this->bytesOf($begun['uuid']), 'Reassembled bytes differ from the original.');
        $this->assertSame(0, DB::table('sheq_file_chunks')->where('file_id', $row->id)->count(),
            'Chunks should be discarded once assembled.');
    }

    public function test_resuming_an_upload_reports_which_parts_are_already_here(): void
    {
        // The app was killed mid-upload. The device asks where it got to.
        $clientId = (string) Str::uuid();
        $context = $this->context(['client_id' => $clientId, 'original_name' => 'a.pdf', 'mime' => 'application/pdf']);

        $begun = $this->service()->beginUpload($this->companyId, $context, 3, $this->userId);
        $this->service()->putChunk($this->companyId, $begun['uuid'], 0, base64_encode('part-zero'));

        $resumed = $this->service()->beginUpload($this->companyId, $context, 3, $this->userId);

        $this->assertTrue($resumed['resumed']);
        $this->assertSame([0], $resumed['received']);
        $this->assertSame($begun['uuid'], $resumed['uuid']);
    }

    public function test_a_repeated_chunk_does_not_corrupt_the_file(): void
    {
        $parts = ['aaa', 'bbb'];

        $begun = $this->service()->beginUpload($this->companyId, $this->context([
            'original_name' => 'b.pdf', 'mime' => 'application/pdf',
        ]), 2, $this->userId);

        $this->service()->putChunk($this->companyId, $begun['uuid'], 0, base64_encode($parts[0]));
        $this->service()->putChunk($this->companyId, $begun['uuid'], 0, base64_encode($parts[0]));
        $this->service()->putChunk($this->companyId, $begun['uuid'], 1, base64_encode($parts[1]));

        $this->assertSame('aaabbb', $this->bytesOf($begun['uuid']));
    }

    // ── Pending media blocks closure (SHEQ-OFF-7) ───────────────────────────────────────────────

    public function test_a_record_with_media_still_uploading_is_reported_as_pending(): void
    {
        $this->assertFalse($this->service()->hasPendingMedia($this->companyId, 'INCIDENT', $this->incidentId));

        $this->service()->beginUpload($this->companyId, $this->context([
            'original_name' => 'c.pdf', 'mime' => 'application/pdf',
        ]), 2, $this->userId);

        $this->assertTrue($this->service()->hasPendingMedia($this->companyId, 'INCIDENT', $this->incidentId),
            'A record whose evidence is mandatory must not be closable while media is in flight.');
    }

    public function test_pending_clears_once_the_upload_completes(): void
    {
        $begun = $this->service()->beginUpload($this->companyId, $this->context([
            'original_name' => 'd.pdf', 'mime' => 'application/pdf',
        ]), 1, $this->userId);

        $this->service()->putChunk($this->companyId, $begun['uuid'], 0, base64_encode('done'));

        $this->assertFalse($this->service()->hasPendingMedia($this->companyId, 'INCIDENT', $this->incidentId));
    }

    // ── Listing and retrieval ───────────────────────────────────────────────────────────────────

    public function test_listing_a_records_files_never_ships_the_bytes(): void
    {
        // Six photographs would otherwise be megabytes of base64 in an Inertia payload.
        $this->service()->store($this->companyId, $this->photo(), $this->context(), $this->userId);

        $files = $this->service()->forSubject($this->companyId, 'INCIDENT', $this->incidentId);

        $this->assertCount(1, $files);
        $this->assertArrayNotHasKey('data', $files[0]);
        $this->assertSame('hazard.jpg', $files[0]['original_name']);
    }

    public function test_a_download_returns_the_original_bytes(): void
    {
        $result = $this->service()->store($this->companyId, $this->photo(), $this->context(), $this->userId);

        $response = $this->service()->download($this->companyId, $result['uuid']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('hazard.jpg', $response->headers->get('Content-Disposition'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_an_unfinished_file_cannot_be_downloaded(): void
    {
        $begun = $this->service()->beginUpload($this->companyId, $this->context([
            'original_name' => 'e.pdf', 'mime' => 'application/pdf',
        ]), 2, $this->userId);

        $this->expectExceptionMessageMatches('/has not finished uploading/');
        $this->service()->download($this->companyId, $begun['uuid']);
    }

    public function test_another_tenants_file_is_not_found_rather_than_forbidden(): void
    {
        // Confirming that a file exists elsewhere is itself a leak.
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-file-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service()->store($this->companyId, $this->photo(), $this->context(), $this->userId);

        $this->expectExceptionMessageMatches('/File not found/');
        $this->service()->download($otherCompany, $result['uuid']);
    }

    /**
     * The stored bytes of a SHEQ file, wherever they live.
     *
     * Reads the way the application does, so these tests keep asserting the file's contents rather
     * than the mechanism that happens to hold them.
     */
    private function bytesOf(string $uuid): string
    {
        $row = DB::table('sheq_files')->where('uuid', $uuid)->first();

        if (! blank($row->storage_path ?? null)) {
            return Storage::disk('documents')->get($row->storage_path);
        }

        return (string) base64_decode((string) $row->data);
    }
}
