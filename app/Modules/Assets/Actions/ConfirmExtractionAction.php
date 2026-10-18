<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetDocumentExtraction;
use App\Modules\Assets\Models\Assets;

class ConfirmExtractionAction
{
    public function execute(AssetDocumentExtraction $extraction, array $confirmedFields, int $confirmedBy): AssetDocumentExtraction
    {
        $extraction->update([
            'confirmed_fields'  => $confirmedFields,
            'confirmed_at'      => now(),
            'confirmed_by'      => $confirmedBy,
            'extraction_status' => 'confirmed',
        ]);

        // Apply non-null confirmed fields to the linked asset
        if ($extraction->asset_id) {
            // The confirm payload sends the asset name as `asset_name`; the column
            // is `name`. Normalise before applying or the name is silently dropped.
            $normalized = collect($confirmedFields);
            if ($normalized->has('asset_name')) {
                $normalized->put('name', $normalized->get('asset_name'));
            }

            $applyable = $normalized
                ->only(['name', 'serial_number', 'purchase_cost', 'purchase_date', 'model_number', 'warranty_expiry'])
                ->filter(fn($v) => $v !== null && $v !== '')
                ->toArray();

            if (! empty($applyable)) {
                Assets::where('id', $extraction->asset_id)->update($applyable);
            }
        }

        return $extraction->refresh();
    }
}
