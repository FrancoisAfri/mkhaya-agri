<?php

namespace App\Modules\SupplierManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Reads an uploaded supplier document (stored in the DB via FileStorage) and uses
 * AI to detect its document type and expiry date — so compliance certificates
 * automatically feed the expiry-alert engine without manual data entry.
 */
class SupplierDocumentAiService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You read business compliance documents (tax clearance, B-BBEE certificate, VAT
registration, letter of good standing, insurance, registration certificate, etc.).
Return ONLY valid JSON:
{"document_type": string|null, "expiry_date": "YYYY-MM-DD"|null,
 "company_name": string|null, "registration_number": string|null, "vat_number": string|null,
 "tax_reference_number": string|null, "certificate_number": string|null, "bbbee_level": string|null,
 "confidence": "low|medium|high"}
Copy names and numbers EXACTLY as printed, including punctuation and spacing. Do not
normalise, correct or complete them, and never infer a value that is not on the page —
return null instead. If there is no expiry/valid-until date, return null for expiry_date.
TXT;

    /**
     * Detect + set the document's expiry_date. Returns the detected expiry (or null).
     * Only overwrites an existing expiry_date when $force is true.
     */
    public function extractExpiry(SupplierDocument $document, bool $force = false): ?string
    {
        if ($document->expiry_date && ! $force) {
            return $document->expiry_date instanceof Carbon
                ? $document->expiry_date->toDateString()
                : (string) $document->expiry_date;
        }

        $text = $this->documentText($document);
        if (trim($text) === '') {
            return null;
        }

        try {
            // The document itself may contain identity numbers — a tax clearance certificate routinely
            // does. They are stripped before the text leaves for a model, for the same reason the
            // screening prompt strips them: a different purpose, in a third party's logs, beyond any
            // retention policy of ours.
            $text = app(\App\Modules\SupplierManagement\DueDiligence\Services\PersonalDataGuard::class)->redactText($text);

            $response = $this->agents->for('suppliers')->ask(new AgentRequest(
                prompt: "Extract the document type and expiry date from this document:\n\n".Str::limit($text, 12000),
                module: 'supplier-management.document-expiry',
                context: ['supplier_document_id' => $document->id],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.0,
                maxTokens: 300,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                return null;
            }

            $data = $response->toArray();

            $this->recordIdentityComparison($document, $data);

            $expiry = $data['expiry_date'] ?? null;

            if (empty($expiry)) {
                return null;
            }

            $date = Carbon::parse($expiry)->toDateString();
            $document->update(['expiry_date' => $date]);

            return $date;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compare the identity printed on the certificate against the supplier it was filed under.
     *
     * A B-BBEE certificate in the name of a different company, or carrying a registration number
     * that is not the supplier's, is one of the cheapest frauds there is and one of the easiest to
     * miss: somebody uploads a genuine certificate belonging to somebody else, and every screen
     * downstream reports the supplier as compliant.
     *
     * Recorded as a `document.authenticity` observation so it lands in the same log, raises the same
     * risk flag and shows on the same Passport as any other check. The AI is doing the reading only —
     * whether the values agree is decided by the same FieldComparator every provider is judged by,
     * and the confidence the model expressed is carried through rather than treated as certainty.
     */
    private function recordIdentityComparison(SupplierDocument $document, array $data): void
    {
        $supplier = $document->supplier;

        if (! $supplier) {
            return;
        }

        $observed = array_filter([
            'company_name'         => $data['company_name'] ?? null,
            'registration_number'  => $data['registration_number'] ?? null,
            'vat_number'           => $data['vat_number'] ?? null,
            'tax_reference_number' => $data['tax_reference_number'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        // Nothing identifying was on the page (or could be read), so there is nothing to compare.
        // Silence is correct here: a document with no company name on it is not evidence of fraud.
        if ($observed === []) {
            return;
        }

        $claimed = array_filter([
            'company_name'         => $supplier->company_name,
            'registration_number'  => $supplier->company_registration_number,
            'vat_number'           => $supplier->vat_registration_number,
            'tax_reference_number' => $supplier->tax_reference_number,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        app(VerificationEngine::class)->verify(
            supplier: $supplier,
            checkType: CheckType::DOCUMENT_AUTHENTICITY,
            claimed: array_intersect_key($claimed, $observed),
            context: [
                'status'   => VerificationStatus::VERIFIED,   // the reading succeeded; the comparison decides
                'observed' => $observed,
                'note'     => sprintf(
                    'Read from "%s" by AI (confidence: %s). AI-extracted — confirm against the document itself before acting.',
                    $document->file_name ?: ($document->documentType->document_type_name ?? 'document'),
                    $data['confidence'] ?? 'unknown'
                ),
            ],
            reference: is_string($data['certificate_number'] ?? null) ? $data['certificate_number'] : null,
            providerCode: 'manual',
        );
    }

    private function documentText(SupplierDocument $document): string
    {
        if (! $document->hasDocument()) {
            return '';
        }

        $binary = (string) $document->bytes();
        $name = Str::lower((string) ($blob['original_name'] ?? $document->file_name ?? ''));
        $mime = (string) ($blob['mime'] ?? $document->file_mime ?? '');

        if ($binary === '') {
            return '';
        }

        if (Str::endsWith($name, '.docx') || str_contains($mime, 'wordprocessingml.document')) {
            return $this->cleanText($this->docxText($binary));
        }
        if (Str::endsWith($name, '.pdf') || str_contains($mime, 'pdf')) {
            return $this->cleanText($this->pdfText($binary));
        }
        if (str_starts_with($mime, 'text/')) {
            return $this->cleanText($binary);
        }

        return '';
    }

    private function docxText(string $binary): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sup_docx_');
        if ($tmp === false) {
            return '';
        }
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive();
        $xml = '';
        if ($zip->open($tmp) === true) {
            $xml = $zip->getFromName('word/document.xml') ?: '';
            $zip->close();
        }
        @unlink($tmp);

        return $xml;
    }

    private function pdfText(string $binary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sup_pdf_');
        $out = tempnam(sys_get_temp_dir(), 'sup_pdf_txt_');
        if ($tmp === false || $out === false) {
            return '';
        }
        file_put_contents($tmp, $binary);
        @shell_exec(sprintf(
            'command -v pdftotext >/dev/null 2>&1 && pdftotext %s %s 2>/dev/null',
            escapeshellarg($tmp),
            escapeshellarg($out)
        ));
        $text = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($tmp);
        @unlink($out);

        return trim($text) !== '' ? $text : '';
    }

    private function cleanText(string $raw): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return trim((string) $text);
    }
}
