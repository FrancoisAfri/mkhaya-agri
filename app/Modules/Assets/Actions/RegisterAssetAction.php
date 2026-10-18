<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetImage;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RegisterAssetAction
{
    public function execute(array $data, int $registeredBy): Assets
    {
        return DB::transaction(function () use ($data, $registeredBy) {
            $category = null;
            if (! empty($data['asset_category_id'])) {
                $category = \App\Modules\Assets\Models\AssetCategory::find($data['asset_category_id']);
            }

            $asset = Assets::create([
                'name'                    => $data['name'],
                'description'             => $data['description']           ?? null,
                'serial_number'           => $data['serial_number']         ?? null,
                'asset_tag'               => $data['asset_tag']             ?? null,
                'asset_number'            => AssetSetting::nextAssetNumber(),
                'asset_make_id'           => $data['asset_make_id']          ?? null,
                'asset_model_id'          => $data['asset_model_id']         ?? null,
                'asset_type_id'           => $data['asset_type_id']         ?? null,
                'asset_category_id'       => $data['asset_category_id']     ?? null,
                'location_id'             => $data['location_id']           ?? null,
                'purchase_cost'           => $data['purchase_cost']         ?? null,
                'price'                   => $data['purchase_cost']         ?? 0,
                'purchase_date'           => $data['purchase_date']         ?? null,
                'warranty_expiry'         => $data['warranty_expiry']       ?? null,
                'condition'               => $data['condition']             ?? 'new',
                'asset_status'            => 'unallocated',
                'status'                  => 1,
                'sars_allowance_section'  => $data['sars_allowance_section']  ?? ($category?->sars_allowance_section?->value),
                'barcode'                 => $data['barcode']                 ?? null,
                'retention_until'         => now()->addYears(7)->toDateString(),
                'next_impairment_review_at' => now()->addYear()->toDateString(),
                // Newly registered assets are unallocated — no current holder until
                // they're assigned. Leaving user_id as the registrar made the
                // bulk-registering admin appear to hold every asset.
                'user_id'                 => null,
            ]);

            AssetCustodyLog::create([
                'asset_id'     => $asset->id,
                'action'       => 'registered',
                'performed_by' => $registeredBy,
                'notes'        => 'Asset registered into the system.',
                'occurred_at'  => now(),
            ]);

            foreach (($data['images'] ?? []) as $i => $imageFile) {
                $path = $imageFile->store("assets/{$asset->uuid}/images", 'public');
                AssetImage::create([
                    'asset_id'      => $asset->id,
                    'path'          => $path,
                    'original_name' => $imageFile->getClientOriginalName(),
                    'size'          => $imageFile->getSize(),
                    'sort_order'    => $i,
                ]);
            }

            return $asset;
        });
    }
}
