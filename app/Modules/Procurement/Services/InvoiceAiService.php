<?php

namespace App\Modules\Procurement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * AI extraction of structured invoice data (header + line items + totals) from an
 * uploaded invoice document or pasted text, via the configured AI agent. Results
 * are written back onto the invoice and its line items, ready for 3-way matching.
 */
class InvoiceAiService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are an accounts-payable clerk. Read the supplier invoice text and return ONLY
valid JSON (no markdown, no prose) with EXACTLY these keys:
{
  "invoice_number": string|null,
  "supplier_name": string|null,
  "invoice_date": "YYYY-MM-DD"|null,
  "due_date": "YYYY-MM-DD"|null,
  "currency": string|null,
  "subtotal": number|null,
  "tax_amount": number|null,
  "total": number|null,
  "po_reference": string|null,
  "line_items": [{"name": string, "sku": string|null, "quantity": number, "unit_price": number, "line_total": number, "tax": number|null}]
}
If a value is unknown use null (or [] for line_items). Numbers must be plain
numbers without currency symbols or thousands separators.
TXT;

    public function extract(SupplierInvoice $invoice, ?string $pastedText = null, ?int $userId = null): SupplierInvoice
    {
        $invoice->update(['ai_status' => 'processing']);

        try {
            $text = trim((string) $pastedText) !== '' ? $pastedText : $this->invoiceText($invoice);
            if (trim((string) $text) === '') {
                throw new \RuntimeException('No readable invoice text found (upload a PDF/DOCX/text invoice or paste the text).');
            }

            $response = $this->agents->for('procurement')->ask(new AgentRequest(
                prompt: "Extract the structured invoice data from the following document.\n\n".Str::limit($text, 16000),
                module: 'procurement.invoice-extract',
                context: ['invoice_id' => $invoice->id],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.0,
                maxTokens: 2500,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?: 'AI extraction failed.');
            }

            $data = $response->toArray();
            $this->applyExtraction($invoice, $data, $response->provider, $response->model);

            return $invoice->refresh();
        } catch (\Throwable $e) {
            $invoice->update(['ai_status' => 'failed', 'notes' => trim((string) $invoice->notes."\nAI: ".$e->getMessage())]);

            return $invoice->refresh();
        }
    }

    private function applyExtraction(SupplierInvoice $invoice, array $data, ?string $provider, ?string $model): void
    {
        $subtotal = $this->num($data['subtotal'] ?? null);
        $tax      = $this->num($data['tax_amount'] ?? null);
        $total    = $this->num($data['total'] ?? null) ?: round($subtotal + $tax, 2);

        $invoice->fill([
            'invoice_number' => $invoice->invoice_number ?: ($data['invoice_number'] ?? null) ?: $invoice->invoice_number,
            'invoice_date'   => $this->date($data['invoice_date'] ?? null) ?? $invoice->invoice_date,
            'due_date'       => $this->date($data['due_date'] ?? null) ?? $invoice->due_date,
            'currency'       => $data['currency'] ?: $invoice->currency,
            'subtotal'       => $subtotal,
            'tax_amount'     => $tax,
            'amount'         => $total ?: $invoice->amount,
            'source'         => 'ai',
            'ai_status'      => 'completed',
            'ai_provider'    => $provider,
            'ai_model'       => $model,
            'ai_extracted'   => json_encode($data),
        ])->save();

        // Rebuild line items from the extraction.
        $invoice->items()->delete();
        foreach (($data['line_items'] ?? []) as $li) {
            if (! is_array($li) || trim((string) ($li['name'] ?? '')) === '') {
                continue;
            }
            $invoice->items()->create([
                'name'       => Str::limit((string) $li['name'], 250, ''),
                'sku'        => $li['sku'] ?? null,
                'quantity'   => $this->num($li['quantity'] ?? 1) ?: 1,
                'unit_price' => $this->num($li['unit_price'] ?? 0),
                'line_total' => $this->num($li['line_total'] ?? 0),
                'tax'        => $this->num($li['tax'] ?? 0),
            ]);
        }
    }

    // ── Document text extraction (PDF via pdftotext CLI, DOCX via zip, text) ──────

    private function invoiceText(SupplierInvoice $invoice): string
    {
        if (! $invoice->hasDocument()) {
            return '';
        }
        $binary = (string) $invoice->bytes();
        $name   = Str::lower((string) ($blob['original_name'] ?? $invoice->file_name ?? ''));
        $mime   = (string) ($blob['mime'] ?? $invoice->file_mime ?? '');

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
        $tmp = tempnam(sys_get_temp_dir(), 'inv_docx_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'inv_pdf_');
        $out = tempnam(sys_get_temp_dir(), 'inv_pdf_txt_');
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

        return $text;
    }

    private function cleanText(string $raw): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return trim((string) $text);
    }

    private function num(mixed $v): float
    {
        if (is_numeric($v)) {
            return round((float) $v, 2);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return $clean === '' ? 0.0 : round((float) $clean, 2);
    }

    private function date(mixed $v): ?string
    {
        if (! $v || ! is_string($v)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
