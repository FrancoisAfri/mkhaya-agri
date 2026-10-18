<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\ShareService;
use App\Modules\Operations\DocumentManagement\Services\WatermarkService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stamping the copies that leave the building.
 *
 * A watermark is not a security control and nothing here treats it as one: it makes a leaked copy
 * traceable and an accidental one obvious. What matters is that it applies to the copy and never to
 * the stored file, and that a file which cannot be stamped is still served rather than withheld.
 */
class WatermarkTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'wm+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]));

        Storage::fake('documents');

        DocumentSetting::query()->delete();
        DocumentSetting::create(['sharing_enabled' => true, 'share_max_days' => 30, 'max_file_size_mb' => 25]);
    }

    private function watermarks(): WatermarkService
    {
        return app(WatermarkService::class);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(600, 200);
        imagefilledrectangle($image, 0, 0, 600, 200, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 90, 'ORIGINAL CONTENT', imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function pdfBytes(): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Service agreement</h1><p>Thirty days notice.</p>')->output();
    }

    private function document(string $bytes, string $extension, string $mime): Document
    {
        $document = Document::create([
            'title' => 'Shared document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ]);

        $path = 'wm/' . Str::random(12) . '.' . $extension;
        Storage::disk('documents')->put($path, $bytes);

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'file.' . $extension, 'stored_name' => basename($path),
            'extension' => $extension, 'mime' => $mime, 'size' => strlen($bytes),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    public function test_a_pdf_is_stamped_and_keeps_its_own_content(): void
    {
        $original = $this->pdfBytes();

        $stamped = $this->watermarks()->apply($original, 'application/pdf', 'pdf', 'Shared with auditor');

        $this->assertNotNull($stamped, 'A well-formed PDF must be stampable.');
        $this->assertNotSame($original, $stamped);
        $this->assertStringStartsWith('%PDF', $stamped);
    }

    public function test_an_image_is_stamped(): void
    {
        $stamped = $this->watermarks()->apply($this->pngBytes(), 'image/png', 'png', 'Shared with auditor');

        $this->assertNotNull($stamped);
        $this->assertNotSame($this->pngBytes(), $stamped);
    }

    public function test_a_format_that_cannot_be_stamped_says_so_rather_than_failing(): void
    {
        // The caller decides what to do about it, and the answer here is to serve the document
        // plain: withholding a file the sender chose to share, because it is a spreadsheet, trades
        // something real for something decorative.
        $this->assertFalse($this->watermarks()->supports('application/vnd.ms-excel', 'xlsx'));
        $this->assertNull($this->watermarks()->apply('rubbish', 'application/octet-stream', 'bin', 'x'));
    }

    public function test_the_stamp_names_the_recipient_and_the_date(): void
    {
        // What makes a leaked copy traceable, and an old one obviously old.
        $text = $this->watermarks()->textFor('auditor@example.test', 'Yello Africa');

        $this->assertStringContainsString('auditor@example.test', $text);
        $this->assertStringContainsString('Yello Africa', $text);
        $this->assertStringContainsString(now()->format('Y'), $text);
    }

    public function test_a_watermarked_share_serves_a_stamped_copy_and_leaves_the_stored_file_alone(): void
    {
        $original = $this->pdfBytes();
        $document = $this->document($original, 'pdf', 'application/pdf');

        $result = app(ShareService::class)->create($document, [
            'allow_download' => true,
            'watermark' => true,
            'recipient_email' => 'auditor@example.test',
        ]);

        auth()->logout();

        $response = $this->get(route('documents.shared.download', $result['token']));

        $response->assertOk();
        $served = $response->getContent();

        $this->assertNotSame($original, $served, 'The served copy should carry the stamp.');
        $this->assertSame(
            $original,
            Storage::disk('documents')->get($document->currentVersion->path),
            'The stored document is the record and must be untouched.',
        );
    }

    public function test_an_unwatermarked_share_serves_the_file_as_it_is(): void
    {
        $original = $this->pdfBytes();
        $document = $this->document($original, 'pdf', 'application/pdf');

        $result = app(ShareService::class)->create($document, ['allow_download' => true, 'watermark' => false]);

        auth()->logout();

        $response = $this->get(route('documents.shared.download', $result['token']));

        $response->assertOk();
        $this->assertSame($original, $response->getContent());
    }

    public function test_a_stamped_copy_goes_out_under_the_same_headers_as_an_ordinary_download(): void
    {
        /*
         * The reason the stamped path reuses the streaming helper. A derivative served through a
         * second, simpler route is how the one file most likely to be forwarded ends up as the one
         * without nosniff on it.
         */
        $document = $this->document($this->pdfBytes(), 'pdf', 'application/pdf');

        $result = app(ShareService::class)->create($document, ['allow_download' => true, 'watermark' => true]);

        auth()->logout();

        $response = $this->get(route('documents.shared.download', $result['token']));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
