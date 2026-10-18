<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;

/**
 * Printable location labels (WMS-004): a QR code carrying the location's barcode value, with the
 * code in large type underneath for the person who reads it rather than scans it.
 *
 * The QR content is the barcode value and nothing else -- no URL. A scanner on the floor hands the
 * value to whichever screen asked for a location, and a URL would tie every label ever printed to
 * this host name.
 */
class LabelSheet
{
    /** @param Collection<int,FinStockBin> $bins */
    public function for(Collection $bins): array
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd()));

        return $bins->map(fn (FinStockBin $b) => [
            'id' => $b->uuid,
            'code' => $b->code,
            'name' => $b->name,
            'level' => $b->level,
            'kind' => $b->kind,
            'barcode' => $b->barcode ?? $b->code,
            'warehouse' => $b->warehouse?->code,
            'qr' => $writer->writeString((string) ($b->barcode ?? $b->code)),
        ])->values()->all();
    }

    /**
     * Product labels: the primary barcode (or the one for the chosen packaging level) as a QR,
     * with the SKU and name. An item with no barcode gets its SKU, which the scanner also resolves.
     *
     * @param  Collection<int,\App\Modules\Finance\Accounting\Models\FinStockItem>  $items
     */
    public function forItems(Collection $items, ?string $level = null): array
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd()));
        $codes = \App\Modules\Operations\WarehouseManagement\Models\ItemBarcode::query()
            ->whereIn('stock_item_id', $items->pluck('id'))->where('is_active', true)->with('packaging:id,level')
            ->orderByDesc('is_primary')->get()->groupBy('stock_item_id');

        return $items->map(function ($item) use ($writer, $codes, $level) {
            $all = $codes[$item->id] ?? collect();
            $pick = $level ? $all->first(fn ($b) => $b->packaging?->level === $level) : null;
            $pick ??= $all->first();
            $value = $pick?->barcode ?? $item->code;

            return [
                'id' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'barcode' => $value,
                'level' => $pick?->packaging?->level, 'qr' => $writer->writeString($value),
            ];
        })->values()->all();
    }
}
