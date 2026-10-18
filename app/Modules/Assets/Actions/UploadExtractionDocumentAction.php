<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetDocumentExtraction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadExtractionDocumentAction
{
    public function execute(UploadedFile $file, int $uploadedBy, ?int $assetId = null): AssetDocumentExtraction
    {
        $uuid     = (string) Str::uuid();
        $path     = $file->storeAs("assets/extractions/{$uuid}", $file->getClientOriginalName(), 'local');

        $extraction = AssetDocumentExtraction::create([
            'uploaded_by'       => $uploadedBy,
            'asset_id'          => $assetId,
            'file_path'         => $path,
            'file_name'         => $file->getClientOriginalName(),
            'file_size'         => $file->getSize(),
            'mime_type'         => $file->getMimeType(),
            'extraction_status' => 'pending',
        ]);

        // Simulate extraction synchronously (no queue needed for placeholder)
        $this->runPlaceholderExtraction($extraction);

        return $extraction->refresh();
    }

    private function runPlaceholderExtraction(AssetDocumentExtraction $extraction): void
    {
        $extraction->update(['extraction_status' => 'processing']);

        // Placeholder: parse filename for hints, return structured null fields
        $fileName = pathinfo($extraction->file_name, PATHINFO_FILENAME);

        $extracted = [
            'asset_name'       => null,
            'serial_number'    => null,
            'purchase_cost'    => null,
            'purchase_date'    => null,
            'vendor_name'      => null,
            'model_number'     => null,
            'warranty_expiry'  => null,
            'raw_text_preview' => "Document: {$fileName} — Automated extraction placeholder. Please review and fill in the fields manually.",
        ];

        $extraction->update([
            'extraction_status' => 'extracted',
            'extracted_fields'  => $extracted,
            'extracted_at'      => now(),
        ]);
    }
}
