<?php

namespace App\Modules\HR\Recruitment\Services;

class CvParsingService
{
    public function extractText(string $filePath, string $mimeType): string
    {
        return match(true) {
            str_contains($mimeType, 'pdf') => $this->extractFromPdf($filePath),
            str_contains($mimeType, 'word') || str_contains($mimeType, 'docx') || str_contains($mimeType, 'openxmlformats') => $this->extractFromDocx($filePath),
            str_contains($mimeType, 'text') => file_get_contents($filePath),
            default => throw new \InvalidArgumentException("Unsupported file type: {$mimeType}"),
        };
    }

    private function extractFromPdf(string $path): string
    {
        // Try pdftotext (poppler-utils, common on Linux/Ubuntu servers)
        if (is_executable('/usr/bin/pdftotext') || shell_exec('which pdftotext 2>/dev/null')) {
            $output = shell_exec("pdftotext -layout " . escapeshellarg($path) . " - 2>/dev/null");
            if ($output !== null && strlen(trim($output)) > 10) {
                return $output;
            }
        }
        // Fallback: read raw binary and attempt to extract text streams
        return $this->extractPdfTextFallback($path);
    }

    private function extractFromDocx(string $path): string
    {
        // DOCX is a ZIP file containing word/document.xml
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Cannot open DOCX file.");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException("word/document.xml not found in DOCX.");
        }
        // Strip XML tags and decode entities
        $text = strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml));
        return html_entity_decode(preg_replace('/\s+/', ' ', $text));
    }

    private function extractPdfTextFallback(string $path): string
    {
        $content = file_get_contents($path);
        // Extract text between BT (begin text) and ET (end text) PDF operators — best-effort
        preg_match_all('/BT(.+?)ET/s', $content, $matches);
        $text = '';
        foreach ($matches[1] ?? [] as $block) {
            preg_match_all('/\(([^)]*)\)/', $block, $strings);
            $text .= implode(' ', $strings[1]) . "\n";
        }
        return $text ?: '[Text extraction failed — manual review required]';
    }
}
