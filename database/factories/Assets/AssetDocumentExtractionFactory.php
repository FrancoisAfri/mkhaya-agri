<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetDocumentExtraction;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetDocumentExtractionFactory extends Factory
{
    protected $model = AssetDocumentExtraction::class;

    public function definition(): array
    {
        return [
            'uploaded_by'       => User::factory(),
            'asset_id'          => null,
            'file_path'         => 'assets/extractions/' . fake()->uuid() . '/invoice.pdf',
            'file_name'         => 'invoice.pdf',
            'file_size'         => 102400,
            'mime_type'         => 'application/pdf',
            'extraction_status' => 'pending',
            'extracted_fields'  => null,
            'confirmed_fields'  => null,
        ];
    }

    public function extracted(): static
    {
        return $this->state([
            'extraction_status' => 'extracted',
            'extracted_fields'  => [
                'asset_name'    => 'Dell Latitude',
                'serial_number' => 'SN-123',
                'purchase_cost' => 25000.00,
            ],
            'extracted_at'      => now(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state([
            'extraction_status' => 'confirmed',
            'confirmed_fields'  => [
                'asset_name'    => 'Dell Latitude',
                'serial_number' => 'SN-123',
                'purchase_cost' => 25000.00,
            ],
            'confirmed_at'      => now(),
        ]);
    }
}
