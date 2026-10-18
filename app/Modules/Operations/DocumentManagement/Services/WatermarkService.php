<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stamping a copy of a document before it leaves the building.
 *
 * A watermark is not a security control and this does not pretend otherwise: anybody determined
 * enough can remove one. What it does is make a leaked copy traceable and an accidental one
 * obvious — a page that says "Shared with auditor@example.test, 29 August 2026" across it is
 * awkward to forward and impossible to mistake for the original.
 *
 * It applies to the copy that is served, never to the stored file. The document in the repository
 * is the record; a watermarked derivative exists only for the length of one response.
 */
class WatermarkService
{
    /** What can be stamped. Everything else is served as it is. */
    public function supports(string $mime, string $extension): bool
    {
        return $this->isPdf($mime, $extension) || $this->isImage($mime, $extension);
    }

    /**
     * Returns the watermarked bytes, or null when it could not be done.
     *
     * Null rather than an exception, and null rather than the original: the caller decides whether
     * an unstampable document should be served plain or refused, and that is a policy question
     * rather than one for a rendering helper.
     */
    public function apply(string $bytes, string $mime, string $extension, string $text): ?string
    {
        try {
            if ($this->isPdf($mime, $extension)) {
                return $this->stampPdf($bytes, $text);
            }

            if ($this->isImage($mime, $extension)) {
                return $this->stampImage($bytes, $text);
            }
        } catch (\Throwable $e) {
            // A malformed PDF, an encrypted one, an image type GD was not built with. Logged rather
            // than raised: the caller's fallback is to refuse the download, and a stack trace in
            // somebody's browser would be the wrong way to tell them.
            Log::warning('Document watermarking failed', [
                'mime' => $mime,
                'reason' => Str::limit($e->getMessage(), 200),
            ]);
        }

        return null;
    }

    /**
     * The line stamped across the page.
     *
     * Names the recipient where there is one, because that is what makes a leaked copy traceable,
     * and the date because a copy shared two years ago should not read as current.
     */
    public function textFor(?string $recipient, ?string $companyName = null): string
    {
        $parts = array_filter([
            $companyName,
            $recipient ? 'Shared with ' . $recipient : 'Shared copy',
            now()->format('j M Y'),
        ]);

        return implode(' · ', $parts);
    }

    /**
     * The line stamped across a document somebody is reading on screen.
     *
     * Names the reader rather than a recipient, and includes the time rather than only the date.
     * The point is different from the share watermark: that one makes a forwarded file traceable to
     * whoever it was sent to, this one makes a photograph of a screen traceable to whoever was
     * sitting in front of it. A stamp saying only the company name would do neither.
     */
    public function textForViewer(?string $viewer, ?string $companyName = null): string
    {
        $parts = array_filter([
            $companyName,
            $viewer ?: __('document_management.watermark_unknown_viewer'),
            now()->format('j M Y H:i'),
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Imports each page of the PDF and lays the text over it.
     *
     * Rebuilt page by page rather than appended to, because a watermark added as a new page is not
     * a watermark. The import is FPDI's, through mpdf, which is already a dependency here.
     */
    private function stampPdf(string $bytes, string $text): ?string
    {
        if (! class_exists(\Mpdf\Mpdf::class)) {
            return null;
        }

        $temp = storage_path('app/mpdf-tmp');

        if (! is_dir($temp)) {
            @mkdir($temp, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => $temp,
            'mode' => 'utf-8',
        ]);

        $pages = $mpdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($bytes));

        if ($pages < 1) {
            return null;
        }

        $mpdf->SetWatermarkText($text);
        $mpdf->showWatermarkText = true;

        // Light enough to read the document through, dark enough to be unmistakable on a printout.
        $mpdf->watermarkTextAlpha = 0.12;
        $mpdf->watermark_font = 'DejaVuSans';

        for ($page = 1; $page <= $pages; $page++) {
            $template = $mpdf->importPage($page);
            $size = $mpdf->getTemplateSize($template);

            $mpdf->AddPageByArray([
                'orientation' => ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P',
                'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0,
                'newformat' => [$size['width'] ?? 210, $size['height'] ?? 297],
            ]);

            $mpdf->useTemplate($template);
        }

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Draws the text across an image, sized to the image rather than fixed.
     *
     * A twelve-point stamp is illegible on a 4000px scan and covers a thumbnail entirely, so the
     * band is a proportion of the height and the text is repeated down the page.
     */
    private function stampImage(string $bytes, string $text): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            imagealphablending($image, true);

            // GD's built-in fonts only go to size 5, so the stamp is drawn small and repeated
            // rather than scaled — no font file is guaranteed to exist on a server.
            $grey = imagecolorallocatealpha($image, 90, 90, 90, 85);

            $step = max(60, (int) ($height / 6));

            for ($y = $step; $y < $height; $y += $step) {
                for ($x = 10; $x < $width; $x += 340) {
                    imagestring($image, 5, $x, $y, $text, $grey);
                }
            }

            ob_start();
            imagepng($image);
            $out = (string) ob_get_clean();

            return $out !== '' ? $out : null;
        } finally {
            imagedestroy($image);
        }
    }

    private function isPdf(string $mime, string $extension): bool
    {
        return str_contains(Str::lower($mime), 'pdf') || Str::lower($extension) === 'pdf';
    }

    private function isImage(string $mime, string $extension): bool
    {
        return str_starts_with(Str::lower($mime), 'image/')
            || in_array(Str::lower($extension), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }
}
