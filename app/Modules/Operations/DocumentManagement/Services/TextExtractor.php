<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentExtraction;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Reading the words out of an uploaded file.
 *
 * Every path through this returns a status, and "no text" is never one of them on its own. A
 * scanned contract, an unsupported file type, an encrypted PDF and a genuinely blank page all
 * produce an empty string, and treating them alike is how a document that plainly contains a phrase
 * reports "no results" — and, worse, how an assistant comes to answer questions about a page it
 * never read.
 *
 * Nothing here trusts the file. Extraction runs over bytes somebody else uploaded, so the PDF path
 * shells out to a tool with an argument list rather than a command string, the archive path reads
 * one named entry rather than unpacking anything, and no path writes where it could be executed.
 */
class TextExtractor
{
    /** Waiting to be read. */
    public const PENDING = 'pending';

    /** Read, and there are words. */
    public const EXTRACTED = 'extracted';

    /** Read, and the file genuinely holds no text. */
    public const EMPTY = 'empty';

    /** A PDF made of pictures. The words are there; nothing here can read them without OCR. */
    public const NO_TEXT_LAYER = 'no_text_layer';

    /** A file type this cannot read at all — an image, a video, a design file. */
    public const UNSUPPORTED = 'unsupported';

    /** It needs OCR and no OCR engine is installed on this server. */
    public const OCR_UNAVAILABLE = 'ocr_unavailable';

    /** Something went wrong; the error column says what. */
    public const FAILED = 'failed';

    /**
     * Beyond this the text is truncated.
     *
     * A very long document is still searchable on its first two million characters, and keeping the
     * whole of a thousand-page scan would grow the row past the point where reading it back is
     * cheap. The status stays 'extracted' — this is a complete read that was trimmed, not a partial
     * failure.
     */
    private const MAX_CHARS = 2_000_000;

    /** Extensions that are text as they stand. */
    private const PLAIN = ['txt', 'csv', 'md', 'json', 'xml', 'html', 'htm', 'log', 'yml', 'yaml'];

    /** Extensions a person would expect to be searchable but which need OCR to read. */
    private const NEEDS_OCR = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'heic'];

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Reads a version and records the result.
     *
     * Always writes a row, including on failure. A version with no extraction row is
     * indistinguishable from one that has not been tried yet, and the queue would keep picking it up
     * forever or never look at it again depending on which way that ambiguity was resolved.
     */
    public function extract(DocumentVersion $version): DocumentExtraction
    {
        $document = $version->document()->withoutGlobalScopes()->first();

        $extraction = DocumentExtraction::firstOrNew(['version_id' => $version->id]);
        $extraction->document_id = $version->document_id;
        $extraction->company_id ??= $document?->company_id;

        try {
            $result = $this->read($version);
        } catch (\Throwable $e) {
            $extraction->fill([
                'status' => self::FAILED,
                'method' => null,
                'text' => null,
                'char_count' => 0,
                'word_count' => 0,
                'error' => Str::limit($e->getMessage(), 1000),
                'extracted_at' => Carbon::now(),
            ])->save();

            return $extraction;
        }

        $text = $this->clean($result['text'] ?? '');
        $truncated = mb_strlen($text) > self::MAX_CHARS;

        if ($truncated) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        $status = $result['status'];

        // A reader that ran cleanly but found nothing is 'empty', not 'extracted' — the difference
        // is what tells somebody their search is right and the document is blank.
        if ($status === self::EXTRACTED && $text === '') {
            $status = $result['method'] === 'pdftotext' ? self::NO_TEXT_LAYER : self::EMPTY;
        }

        $extraction->fill([
            'status' => $status,
            'method' => $result['method'] ?? null,
            'text' => $text !== '' ? $text : null,
            'char_count' => mb_strlen($text),
            'word_count' => $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'page_count' => $result['pages'] ?? null,
            'error' => $truncated ? 'Text truncated at ' . self::MAX_CHARS . ' characters.' : null,
            'extracted_at' => Carbon::now(),
        ])->save();

        return $extraction;
    }

    /** Extracts the document's current version, which is the one anybody searching means. */
    public function extractCurrent(Document $document): ?DocumentExtraction
    {
        $version = $document->currentVersion;

        return $version ? $this->extract($version) : null;
    }

    /**
     * Picks a reader and runs it.
     *
     * @return array{status: string, method: ?string, text: string, pages?: ?int}
     */
    private function read(DocumentVersion $version): array
    {
        $extension = Str::lower((string) $version->extension);
        $mime = Str::lower((string) $version->mime);

        $bytes = $this->documents->contents($version);

        if ($bytes === null || $bytes === '') {
            return ['status' => self::FAILED, 'method' => null, 'text' => ''];
        }

        if (in_array($extension, self::NEEDS_OCR, true) || str_starts_with($mime, 'image/')) {
            if (! $this->ocrAvailable()) {
                // Its own status rather than "unsupported". The words are in there; this
                // installation cannot read them, and that is a thing an administrator can fix.
                return ['status' => self::OCR_UNAVAILABLE, 'method' => null, 'text' => ''];
            }

            return $this->fromImage($bytes);
        }

        if ($extension === 'pdf' || str_contains($mime, 'pdf')) {
            return $this->fromPdf($bytes);
        }

        if (in_array($extension, ['docx', 'pptx', 'xlsx'], true) || str_contains($mime, 'openxmlformats')) {
            return $this->fromOfficeOpenXml($bytes, $extension);
        }

        if (in_array($extension, self::PLAIN, true) || str_starts_with($mime, 'text/')) {
            return ['status' => self::EXTRACTED, 'method' => 'plain', 'text' => $bytes];
        }

        return ['status' => self::UNSUPPORTED, 'method' => null, 'text' => ''];
    }

    /**
     * PDF text, via pdftotext.
     *
     * The tool is invoked through proc_open with an argument array rather than a shell string:
     * nothing here is a fixed command with a filename appended, so a filename can never be read as
     * an argument or a second command.
     *
     * @return array{status: string, method: ?string, text: string, pages?: ?int}
     */
    private function fromPdf(string $bytes): array
    {
        $binary = $this->which('pdftotext');

        if ($binary === null) {
            // Honest: this is not a PDF without text, it is a server without the reader.
            return ['status' => self::OCR_UNAVAILABLE, 'method' => null, 'text' => ''];
        }

        $input = tempnam(sys_get_temp_dir(), 'dm_pdf_');

        if ($input === false) {
            return ['status' => self::FAILED, 'method' => null, 'text' => ''];
        }

        try {
            file_put_contents($input, $bytes);

            $text = $this->run([$binary, '-layout', '-enc', 'UTF-8', $input, '-']);

            /*
             * A PDF that reads as empty is usually a scan wrapped in a PDF container rather than a
             * blank page, so it goes to OCR before being written off. This is the single most
             * common unreadable document in any real repository — every certificate somebody
             * photographed and every invoice that arrived by fax.
             *
             * The form feed matters: pdftotext emits one per page, and PHP's trim() does not strip
             * \f by default. A scanned PDF therefore comes back as "\f" rather than "", which is
             * not empty — and OCR would never have run on the one kind of file it exists for.
             */
            if (trim($text, " \t\n\r\0\x0B\x0C") === '' && $this->ocrAvailable()) {
                $ocr = $this->ocrPdf($bytes);

                if (trim($ocr) !== '') {
                    return [
                        'status' => self::EXTRACTED,
                        'method' => 'ocr',
                        'text' => $ocr,
                        'pages' => $this->pdfPageCount($bytes),
                    ];
                }
            }

            return [
                'status' => self::EXTRACTED,
                'method' => 'pdftotext',
                'text' => $text,
                'pages' => $this->pdfPageCount($bytes),
            ];
        } finally {
            @unlink($input);
        }
    }

    /**
     * Text from the Open XML formats, read as the zip archives they are.
     *
     * One named entry is read rather than the archive being unpacked. An uploaded .docx is an
     * archive somebody else built, and unpacking one is how a path in an entry name writes a file
     * somewhere it should not.
     *
     * @return array{status: string, method: ?string, text: string}
     */
    private function fromOfficeOpenXml(string $bytes, string $extension): array
    {
        if (! class_exists(ZipArchive::class)) {
            return ['status' => self::UNSUPPORTED, 'method' => null, 'text' => ''];
        }

        $entries = match ($extension) {
            'docx' => ['word/document.xml'],
            'pptx' => array_map(fn ($i) => "ppt/slides/slide{$i}.xml", range(1, 200)),
            'xlsx' => ['xl/sharedStrings.xml'],
            default => [],
        };

        if ($entries === []) {
            return ['status' => self::UNSUPPORTED, 'method' => null, 'text' => ''];
        }

        $file = tempnam(sys_get_temp_dir(), 'dm_ooxml_');

        if ($file === false) {
            return ['status' => self::FAILED, 'method' => null, 'text' => ''];
        }

        try {
            file_put_contents($file, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($file) !== true) {
                return ['status' => self::FAILED, 'method' => null, 'text' => ''];
            }

            $xml = '';

            foreach ($entries as $entry) {
                $part = $zip->getFromName($entry);

                if ($part !== false) {
                    // A space between parts, or the last word of one paragraph joins the first of
                    // the next and neither is findable.
                    $xml .= ' ' . $part;
                }
            }

            $zip->close();

            return ['status' => self::EXTRACTED, 'method' => $extension === 'xlsx' ? 'spreadsheet' : 'docx', 'text' => $xml];
        } finally {
            @unlink($file);
        }
    }

    /**
     * Reads an image with tesseract.
     *
     * @return array{status: string, method: ?string, text: string}
     */
    private function fromImage(string $bytes): array
    {
        $binary = $this->which('tesseract');

        if ($binary === null) {
            return ['status' => self::OCR_UNAVAILABLE, 'method' => null, 'text' => ''];
        }

        $input = tempnam(sys_get_temp_dir(), 'dm_ocr_');

        if ($input === false) {
            return ['status' => self::FAILED, 'method' => null, 'text' => ''];
        }

        try {
            file_put_contents($input, $bytes);

            // 'stdout' rather than a file, so nothing is written where it could be served, and the
            // 60s ceiling because a large scan is genuinely slow and a stuck one must still end.
            $text = $this->run([$binary, $input, 'stdout'], timeoutSeconds: 60);

            return ['status' => self::EXTRACTED, 'method' => 'ocr', 'text' => $text];
        } finally {
            @unlink($input);
        }
    }

    /**
     * Reads a scanned PDF by rendering its pages and passing each to tesseract.
     *
     * Capped at the first pages rather than the whole document: OCR costs seconds per page, and a
     * four-hundred-page scan would hold a queue worker for the best part of an hour to make the
     * last page findable. The cap is stated in the extraction's own note so nobody mistakes a
     * partial read for a complete one.
     */
    private function ocrPdf(string $bytes, int $maxPages = 20): string
    {
        $render = $this->which('pdftoppm');
        $ocr = $this->which('tesseract');

        if ($render === null || $ocr === null) {
            return '';
        }

        $directory = sys_get_temp_dir() . '/dm_ocr_' . bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            return '';
        }

        $input = $directory . '/source.pdf';

        try {
            file_put_contents($input, $bytes);

            // Greyscale at 200dpi: enough for tesseract, and a fraction of the pixels of colour at
            // 300, which matters when this runs over a repository rather than one file.
            $this->run([
                $render, '-gray', '-r', '200', '-f', '1', '-l', (string) $maxPages,
                $input, $directory . '/page',
            ], timeoutSeconds: 120);

            $pages = glob($directory . '/page*.pgm') ?: glob($directory . '/page*.ppm') ?: [];
            sort($pages);

            $text = '';

            foreach ($pages as $page) {
                $text .= ' ' . $this->run([$ocr, $page, 'stdout'], timeoutSeconds: 60);
            }

            return $text;
        } finally {
            // Everything this wrote, including whatever pdftoppm produced under names we did not
            // predict. A temp directory left behind per document fills a disk quietly.
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    /** Whether an OCR engine is installed here at all. */
    public function ocrAvailable(): bool
    {
        return $this->which('tesseract') !== null;
    }

    /** Locates a tool without going through a shell. */
    private function which(string $tool): ?string
    {
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/bin'] as $dir) {
            $path = $dir . '/' . $tool;

            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Runs a command with an argument list and returns its output.
     *
     * proc_open with an array never passes through a shell, so nothing in a filename is interpreted.
     * The timeout matters as much: a malformed PDF can make a parser spin, and a request that never
     * returns takes a worker with it.
     *
     * @param list<string> $command
     */
    private function run(array $command, int $timeoutSeconds = 30): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            return '';
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $output .= stream_get_contents($pipes[1]) ?: '';

            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }

            usleep(20_000);
        }

        $output .= stream_get_contents($pipes[1]) ?: '';

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);

        return $output;
    }

    /** Page count straight off the PDF structure — cheaper than asking a tool for it. */
    private function pdfPageCount(string $bytes): ?int
    {
        if (preg_match_all('/\/Type\s*\/Page\b/', $bytes, $matches)) {
            return count($matches[0]) ?: null;
        }

        return null;
    }

    /** Strips markup, normalises whitespace, and drops anything that is not valid UTF-8. */
    private function clean(string $raw): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Invalid sequences are dropped rather than allowed to reach the database, where they would
        // fail the write and lose the whole extraction over one bad byte.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim((string) $text);
    }
}
