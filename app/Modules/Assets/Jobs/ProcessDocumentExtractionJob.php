<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Models\AssetDocument;
use App\Modules\Assets\Models\AssetDocumentExtraction;
use App\Modules\Assets\Models\AssetInvoiceData;
use App\Modules\Assets\Models\AssetWarrantyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * M7 — Document Extraction Job
 * 
 * Queued job that processes uploaded documents using AI extraction.
 * In production, this would call external OCR/NLP services.
 */
class ProcessDocumentExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private AssetDocument $document
    ) {
    }

    public function handle(): void
    {
        $this->document->update([
            'extraction_status'    => 'processing',
            'extraction_started_at' => now(),
        ]);

        try {
            // Simulate AI extraction (in production: call OCR/NLP API)
            $extractedText = $this->extractText();
            $structuredData = $this->parseStructuredData($extractedText);

            // Store extraction result
            $extraction = AssetDocumentExtraction::create([
                'document_id'        => $this->document->id,
                'extracted_text'     => $extractedText,
                'extracted_data'     => $structuredData,
                'confidence_score'   => $this->calculateConfidence($structuredData),
                'extraction_method'  => 'hybrid',
                'model_version'      => 'v1.0',
                'validation_status'  => 'pending',
            ]);

            // Type-specific processing
            match ($this->document->document_type) {
                'invoice'  => $this->processInvoice($extraction),
                'warranty' => $this->processWarranty($extraction),
                default    => null,
            };

            $this->document->update([
                'extraction_status'       => 'completed',
                'extraction_completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            $this->document->update([
                'extraction_status' => 'failed',
            ]);

            throw $e;
        }
    }

    private function extractText(): string
    {
        // Placeholder: In production, use Tesseract OCR, AWS Textract, or Google Vision API
        $filePath = Storage::path($this->document->file_path);
        
        // Simulate extraction
        return "Extracted text from {$this->document->document_name}";
    }

    private function parseStructuredData(string $text): array
    {
        // Placeholder: In production, use NLP models to extract structured fields
        return [
            'raw_text' => $text,
            'entities' => [],
        ];
    }

    private function calculateConfidence(array $data): int
    {
        // Simple confidence scoring
        return !empty($data['entities']) ? 85 : 50;
    }

    private function processInvoice(AssetDocumentExtraction $extraction): void
    {
        // Extract invoice-specific fields
        AssetInvoiceData::create([
            'document_id'    => $this->document->id,
            'invoice_number' => $extraction->extracted_data['invoice_number'] ?? null,
            'invoice_date'   => $extraction->extracted_data['invoice_date'] ?? null,
            'supplier_name'  => $extraction->extracted_data['supplier_name'] ?? null,
            'total_amount'   => $extraction->extracted_data['total_amount'] ?? null,
            'currency'       => 'ZAR',
        ]);
    }

    private function processWarranty(AssetDocumentExtraction $extraction): void
    {
        // Extract warranty-specific fields
        AssetWarrantyDocument::create([
            'document_id'          => $this->document->id,
            'warranty_provider'    => $extraction->extracted_data['provider'] ?? null,
            'warranty_type'        => 'manufacturer',
            'coverage_start_date'  => $extraction->extracted_data['start_date'] ?? null,
            'coverage_end_date'    => $extraction->extracted_data['end_date'] ?? null,
        ]);
    }
}
