<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetPurchaseRequest;
use App\Modules\Assets\Models\AssetPurchaseRequestItem;
use Illuminate\Support\Facades\DB;

class CreateAssetsFromPurchaseRequestAction
{
    public function __construct(private RegisterAssetAction $register) {}

    public function execute(AssetPurchaseRequest $request, int $createdBy): array
    {
        abort_unless($request->status->value === 'delivered', 422, 'Assets can only be created from a delivered request.');

        return DB::transaction(function () use ($request, $createdBy) {
            // Lock the request row so two concurrent create-assets calls serialise —
            // otherwise both read the same "already linked" count and each create a
            // full `quantity` of assets (2× duplicates).
            $request = AssetPurchaseRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            $alreadyLinked = AssetPurchaseRequestItem::where('purchase_request_id', $request->id)
                ->whereNotNull('asset_id')
                ->count();

            $created = [];

            for ($i = $alreadyLinked; $i < $request->quantity; $i++) {
                $asset = $this->register->execute([
                    'name'              => $request->item_description,
                    'asset_category_id' => $request->asset_category_id,
                    'purchase_cost'     => $request->actual_unit_cost ?? $request->estimated_unit_cost,
                    'purchase_date'     => $request->delivered_at?->toDateString(),
                    'description'       => "Created from purchase request {$request->request_number}",
                ], $createdBy);

                AssetPurchaseRequestItem::create([
                    'purchase_request_id' => $request->id,
                    'asset_id'            => $asset->id,
                    'asset_number'        => $asset->asset_number,
                ]);

                $created[] = $asset;
            }

            return $created;
        });
    }
}
