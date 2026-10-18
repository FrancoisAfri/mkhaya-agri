<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Models\ItemBarcode;
use App\Modules\Operations\WarehouseManagement\Models\ItemIdentifier;
use App\Modules\Operations\WarehouseManagement\Models\ItemPackaging;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Barcodes against items (WMS-012): registration with content validation, duplicate detection
 * with a controlled exception, internal code generation, and turning a scan into an item, a
 * quantity and -- for GS1 content -- a batch, serial and dates.
 *
 * A scan that could mean two items is refused, never resolved to the first match: picking the
 * wrong one of two products sharing a supplier's recycled EAN is exactly the error a scanner is
 * there to prevent.
 */
class BarcodeRegistry
{
    /** EAN-13 prefixes 20-29 are reserved by GS1 for a company's internal use. */
    private const INTERNAL_PREFIX = '20';

    public function __construct(private readonly Gs1Parser $gs1, private readonly ItemCatalogue $catalogue) {}

    public function register(FinStockItem $item, array $data, ?string $duplicateReason = null, bool $mayApproveDuplicate = false): ItemBarcode
    {
        $code = trim((string) $data['barcode']);
        $symbology = strtoupper($data['symbology'] ?? $this->guessSymbology($code));
        $this->validateContent($code, $symbology);

        if (! empty($data['packaging_id'])) {
            $pack = ItemPackaging::query()->findOrFail($data['packaging_id']);
            if ($pack->stock_item_id !== $item->id) {
                throw new RuntimeException('That packaging level belongs to another item.');
            }
        }

        $others = $this->activeMatches($code)->where('stock_item_id', '!=', $item->id)->with('item:id,code')->get();
        if ($others->isNotEmpty()) {
            $names = $others->map(fn ($b) => $b->item?->code)->filter()->implode(', ');
            if (trim((string) $duplicateReason) === '') {
                throw new RuntimeException("Barcode {$code} is already registered to {$names}. Registering it again needs a reason and approval.");
            }
            if (! $mayApproveDuplicate) {
                throw new RuntimeException("Barcode {$code} is already registered to {$names}; only somebody allowed to approve duplicate barcodes may register it again.");
            }
        }

        return DB::transaction(function () use ($item, $data, $code, $symbology, $others, $duplicateReason) {
            if (! empty($data['is_primary'])) {
                ItemBarcode::query()->where('stock_item_id', $item->id)->update(['is_primary' => false]);
            }

            $barcode = ItemBarcode::create([
                'company_id' => $item->company_id, 'stock_item_id' => $item->id, 'barcode' => $code,
                'symbology' => $symbology, 'source' => strtoupper($data['source'] ?? 'SUPPLIER'),
                'packaging_id' => $data['packaging_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null,
                'is_primary' => (bool) ($data['is_primary'] ?? ! ItemBarcode::query()->where('stock_item_id', $item->id)->exists()),
                'is_active' => true,
                'duplicate_reason' => $others->isNotEmpty() ? $duplicateReason : null,
                'duplicate_approved_by' => $others->isNotEmpty() ? Auth::id() : null,
                'created_by' => Auth::id(),
            ]);

            $this->catalogue->event($item, 'BARCODE', ['barcode' => [null, $code]], $others->isNotEmpty() ? "Duplicate allowed: {$duplicateReason}" : null);

            return $barcode;
        });
    }

    public function deactivate(ItemBarcode $barcode): void
    {
        DB::transaction(function () use ($barcode) {
            $barcode->update(['is_active' => false, 'is_primary' => false]);
            $this->catalogue->event($barcode->item, 'BARCODE', ['barcode' => [$barcode->barcode, 'deactivated']]);
        });
    }

    /** A new internal EAN-13 in the 20-prefix range, unique in the company, registered to the item. */
    public function generateInternal(FinStockItem $item, ?ItemPackaging $packaging = null): ItemBarcode
    {
        return DB::transaction(function () use ($item, $packaging) {
            // Serialise generation per company so two people never mint the same number.
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-internal-barcode:'.$item->company_id)]);

            $last = ItemBarcode::query()->where('symbology', 'INTERNAL')->where('barcode', 'like', self::INTERNAL_PREFIX.'%')
                ->orderByDesc('barcode')->value('barcode');
            $next = $last ? (int) substr($last, 2, 10) + 1 : 1;
            $body = self::INTERNAL_PREFIX.str_pad((string) $next, 10, '0', STR_PAD_LEFT);

            return $this->register($item, [
                'barcode' => $body.$this->checkDigit($body), 'symbology' => 'INTERNAL', 'source' => 'INTERNAL',
                'packaging_id' => $packaging?->id,
            ]);
        });
    }

    /**
     * What a scan means.
     *
     * @return array{item: FinStockItem, packaging: ?ItemPackaging, qty_in_base: float, matched_by: string,
     *   gs1: ?array, batch: ?string, serial: ?string, expiry: ?string, production_date: ?string, best_before: ?string}
     */
    public function resolve(string $scan, ?int $supplierId = null): array
    {
        $scan = trim($scan);
        if ($scan === '') {
            throw new InvalidArgumentException('The scan is empty.');
        }

        $gs1 = null;
        $lookup = [$scan];
        if (Gs1Parser::looksLikeGs1($scan)) {
            $gs1 = $this->gs1->parse($scan);
            $gtin = $gs1['gtin'] ?? $gs1['content_gtin'];
            if ($gtin === null) {
                throw new InvalidArgumentException("That is a pallet's SSCC ({$gs1['sscc']}), not a product barcode.");
            }
            $lookup = $this->gtinForms($gtin);
        } elseif (($gtin = Gs1Parser::normaliseGtin($scan)) !== null) {
            $lookup = $this->gtinForms($gtin);
        }

        // 1. Registered barcodes.
        $matches = ItemBarcode::query()->whereIn('barcode', $lookup)->where('is_active', true)
            ->with(['item', 'packaging'])->get();
        $by = 'barcode';

        // 2. Packaging GTINs.
        if ($matches->isEmpty()) {
            $packs = ItemPackaging::query()->whereIn('gtin', $lookup)->where('is_active', true)->with('item')->get();
            $matches = $packs->map(fn ($p) => (object) ['item' => $p->item, 'packaging' => $p, 'supplier_id' => null, 'stock_item_id' => $p->stock_item_id]);
            $by = 'packaging_gtin';
        }

        // 3. A supplier's or customer's own code, then the SKU itself.
        if ($matches->isEmpty()) {
            $ids = ItemIdentifier::query()->where('code', $scan)->with('item')->get();
            $matches = $ids->map(fn ($i) => (object) ['item' => $i->item, 'packaging' => null, 'supplier_id' => $i->supplier_id, 'stock_item_id' => $i->stock_item_id]);
            $by = 'identifier';
        }
        if ($matches->isEmpty()) {
            $item = FinStockItem::query()->where('code', $scan)->first();
            $matches = $item ? collect([(object) ['item' => $item, 'packaging' => null, 'supplier_id' => null, 'stock_item_id' => $item->id]]) : collect();
            $by = 'sku';
        }

        if ($matches->isEmpty()) {
            throw new InvalidArgumentException("Nothing is registered under {$scan}.");
        }

        // A controlled duplicate is told apart by the supplier on the delivery being received.
        if ($matches->unique('stock_item_id')->count() > 1 && $supplierId !== null) {
            $narrowed = $matches->filter(fn ($m) => (int) $m->supplier_id === $supplierId);
            $matches = $narrowed->isNotEmpty() ? $narrowed : $matches;
        }
        if ($matches->unique('stock_item_id')->count() > 1) {
            $codes = $matches->map(fn ($m) => $m->item?->code)->unique()->implode(', ');
            throw new InvalidArgumentException("{$scan} could be any of {$codes}. Scan the item's own label or choose it by hand.");
        }

        $match = $matches->first();

        return [
            'item' => $match->item,
            'packaging' => $match->packaging,
            'qty_in_base' => $match->packaging ? (float) $match->packaging->qty_in_base : 1.0,
            'matched_by' => $by,
            'gs1' => $gs1,
            'batch' => $gs1['batch'] ?? null,
            'serial' => $gs1['serial'] ?? null,
            'expiry' => $gs1['expiry'] ?? null,
            'production_date' => $gs1['production_date'] ?? null,
            'best_before' => $gs1['best_before'] ?? null,
            'count' => $gs1['count'] ?? null,
            'net_weight_kg' => $gs1['net_weight_kg'] ?? null,
        ];
    }

    /** Barcodes registered against more than one item, for the duplicate review. */
    public function duplicates(): array
    {
        return ItemBarcode::query()->where('is_active', true)
            ->select('barcode')->groupBy('barcode')->havingRaw('COUNT(DISTINCT stock_item_id) > 1')
            ->pluck('barcode')->all();
    }

    private function activeMatches(string $code)
    {
        $forms = ($g = Gs1Parser::normaliseGtin($code)) !== null ? $this->gtinForms($g) : [$code];

        return ItemBarcode::query()->whereIn('barcode', $forms)->where('is_active', true);
    }

    /** A GTIN is stored as printed; the same number may be printed as 8, 12, 13 or 14 digits. */
    private function gtinForms(string $gtin14): array
    {
        $trimmed = ltrim($gtin14, '0');

        return array_values(array_unique(array_filter([
            $gtin14,
            substr($gtin14, 1),            // EAN-13
            substr($gtin14, 2),            // UPC-A
            strlen($trimmed) <= 8 ? str_pad($trimmed, 8, '0', STR_PAD_LEFT) : null,
        ])));
    }

    private function validateContent(string $code, string $symbology): void
    {
        if ($code === '' || strlen($code) > 64) {
            throw new RuntimeException('A barcode must be between 1 and 64 characters.');
        }
        if (preg_match('/[\x00-\x1C\x1E\x1F\x7F]/', $code)) {
            throw new RuntimeException('That barcode contains control characters; register the product code, not a raw scan.');
        }

        $digits = ['EAN13' => 13, 'EAN8' => 8, 'UPCA' => 12, 'GTIN14' => 14, 'INTERNAL' => 13][$symbology] ?? null;
        if ($digits !== null) {
            if (! ctype_digit($code) || strlen($code) !== $digits) {
                throw new RuntimeException("{$symbology} barcodes are exactly {$digits} digits.");
            }
            if (! Gs1Parser::checkDigitValid($code)) {
                throw new RuntimeException("{$code} has a wrong check digit; it was probably mistyped.");
            }
        }
        if ($symbology === 'GS1_128') {
            try {
                $this->gs1->parse($code);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
        }
        if (! in_array($symbology, ItemBarcode::SYMBOLOGIES, true)) {
            throw new RuntimeException("Unknown barcode type {$symbology}.");
        }
    }

    private function guessSymbology(string $code): string
    {
        return match (true) {
            ctype_digit($code) && strlen($code) === 13 => 'EAN13',
            ctype_digit($code) && strlen($code) === 8 => 'EAN8',
            ctype_digit($code) && strlen($code) === 12 => 'UPCA',
            ctype_digit($code) && strlen($code) === 14 => 'GTIN14',
            default => 'CODE128',
        };
    }

    private function checkDigit(string $body): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }
}
