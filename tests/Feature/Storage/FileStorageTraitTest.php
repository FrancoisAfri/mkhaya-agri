<?php

namespace Tests\Feature\Storage;

use App\Core\User\Trait\FileStorage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The shared upload trait, used by roughly twenty callers.
 *
 * The behaviour under test is mostly about what it does *not* do to a file any more: it used to
 * re-encode every image as JPEG, which silently destroyed transparency and animation.
 */
class FileStorageTraitTest extends TestCase
{
    use FileStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storage.malware.driver' => 'none']);
    }

    private function upload(string $binary, string $name, string $type): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $binary);

        return new UploadedFile($path, $name, $type, null, true);
    }

    private function transparentPng(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 127));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    // ── Images keep being what they were ─────────────────────────────────────

    public function test_a_png_stays_a_png(): void
    {
        $stored = $this->saveFileToDatabase($this->upload($this->transparentPng(), 'logo.png', 'image/png'));

        // Everything downstream reads this field to decide how to render the file, and it used to
        // say image/jpeg for every image regardless of what was stored.
        $this->assertSame('image/png', $stored['mime']);
        $this->assertStringStartsWith("\x89PNG", base64_decode($stored['data']));
    }

    public function test_transparency_survives(): void
    {
        $stored = $this->saveFileToDatabase($this->upload($this->transparentPng(), 'logo.png', 'image/png'));

        $image = imagecreatefromstring(base64_decode($stored['data']));
        $alpha = (imagecolorat($image, 5, 5) >> 24) & 0x7F;
        imagedestroy($image);

        // A logo re-encoded as JPEG came back on a black box. This is the assertion that fails if
        // anyone reinstates that.
        $this->assertGreaterThan(0, $alpha, 'The transparent pixel came back opaque.');
    }

    public function test_an_image_within_the_size_limit_is_stored_untouched(): void
    {
        $original = $this->transparentPng();

        $stored = $this->saveFileToDatabase($this->upload($original, 'logo.png', 'image/png'));

        // The file a user uploaded is the file they expect back; re-encoding needs a reason, and
        // for an image already small enough there isn't one.
        $this->assertSame($original, base64_decode($stored['data']));
    }

    public function test_an_oversized_image_is_resized_but_keeps_its_format(): void
    {
        $wide = $this->transparentPng(2400, 600);

        $stored = $this->saveFileToDatabase($this->upload($wide, 'banner.png', 'image/png'));
        $image = imagecreatefromstring(base64_decode($stored['data']));

        $this->assertSame($this->imageMaxWidth, imagesx($image));
        $this->assertSame('image/png', $stored['mime']);
        imagedestroy($image);
    }

    public function test_a_gif_is_never_re_encoded(): void
    {
        $image = imagecreatetruecolor(30, 30);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();
        imagedestroy($image);

        $stored = $this->saveFileToDatabase($this->upload($gif, 'spinner.gif', 'image/gif'));

        // GD reads one frame of an animated GIF, so re-encoding one throws the animation away.
        $this->assertSame($gif, base64_decode($stored['data']));
        $this->assertSame('image/gif', $stored['mime']);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_the_declared_type_is_not_trusted(): void
    {
        // Claims to be a PDF; the bytes are a PHP script.
        $file = $this->upload("<?php system(\$_GET['c']); ?>", 'invoice.pdf', 'application/pdf');

        $this->expectExceptionMessageMatches('/not allowed/');
        $this->saveFileToDatabase($file);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/too large/i');
        $this->saveFileToDatabase($this->upload(str_repeat('x', 11 * 1024 * 1024), 'big.pdf', 'application/pdf'));
    }

    public function test_uploads_are_scanned(): void
    {
        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-' . 'ANTIVIRUS-TEST-FILE!$H+H*';

        $this->expectException(\InvalidArgumentException::class);
        $this->saveFileToDatabase($this->upload($eicar, 'notes.pdf', 'application/pdf'));
    }

    // ── Serving ──────────────────────────────────────────────────────────────

    public function test_a_download_is_inert_and_its_filename_cannot_escape_the_header(): void
    {
        $response = $this->downloadResponse(base64_encode('%PDF-1.4'), 'application/pdf', 'evil"; filename="x.pdf');

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, substr_count($response->headers->get('Content-Disposition'), 'filename='));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
