<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Barcode / QR tagging + scan-to-find for the Asset Management module.
 *
 * Tenancy: on web requests Postgres RLS auto-scopes the `assets` table to the
 * active company, so plain Eloquent queries here are already tenant-safe.
 */
class AssetBarcodeController extends Controller
{
    /**
     * The scan UI — a manual/scanner code entry page.
     */
    public function scanPage(): Response
    {
        return Inertia::render('Modules/Assets/Scan/Index');
    }

    /**
     * Resolve a scanned/typed code to an asset.
     *
     * Matches on `barcode` OR `asset_number`, case-insensitively. When an asset
     * has no `barcode`, its (unique) `asset_number` is the scannable code.
     */
    public function resolve(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            return response()->json(['found' => false, 'asset' => null]);
        }

        $asset = Assets::query()
            ->with('location:id,name')
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(barcode) = ?', [mb_strtolower($code)])
                    ->orWhereRaw('LOWER(asset_number) = ?', [mb_strtolower($code)]);
            })
            ->first();

        if (! $asset) {
            return response()->json(['found' => false, 'asset' => null]);
        }

        return response()->json([
            'found' => true,
            'asset' => [
                'id'           => $asset->id,
                'uuid'         => $asset->uuid,
                'asset_number' => $asset->asset_number,
                'name'         => $asset->name,
                'asset_status' => $asset->asset_status,
                'location'     => $asset->location?->name,
            ],
        ]);
    }

    /**
     * Printable single-asset label. QR is rendered client-side in the Vue page.
     */
    public function label(Assets $asset): Response
    {
        return Inertia::render('Modules/Assets/Labels/Show', [
            'asset' => $this->labelPayload($asset),
        ]);
    }

    /**
     * Printable sheet of many labels for bulk tagging.
     */
    public function bulkLabels(Request $request): Response
    {
        $ids = collect($request->input('ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->all();

        $assets = empty($ids)
            ? collect()
            : Assets::query()->whereIn('id', $ids)->get();

        return Inertia::render('Modules/Assets/Labels/Sheet', [
            'assets' => $assets->map(fn (Assets $asset) => $this->labelPayload($asset))->values(),
        ]);
    }

    /**
     * Backfill a barcode from the (unique) asset_number when one is not set.
     */
    public function generateBarcode(Assets $asset): RedirectResponse
    {
        if (blank($asset->barcode)) {
            $asset->barcode = $asset->asset_number;
            $asset->save();
        }

        return back();
    }

    /**
     * Shape a single asset for a printable label. Falls back to asset_number as
     * the scannable code when no explicit barcode exists.
     */
    private function labelPayload(Assets $asset): array
    {
        return [
            'id'           => $asset->id,
            'asset_number' => $asset->asset_number,
            'name'         => $asset->name,
            'code'         => $asset->barcode ?: $asset->asset_number,
        ];
    }
}
