<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PackageContent;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Packing (WMS-064): what was picked, checked into handling units, each with an SSCC.
 *
 * Packing checks against the pick, not against the order: a line cannot be packed beyond what was
 * picked for it, and a scan of the wrong item is refused. A closed package can be reopened
 * (repacking) until it ships; after that its contents are history.
 */
class Packing
{
    public function __construct(private readonly BarcodeRegistry $barcodes) {}

    public function open(FulfilmentOrder $order, string $type, User $user): Package
    {
        if (! in_array($order->status, ['PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'], true)) {
            throw new RuntimeException("{$order->number} has nothing picked to pack.");
        }
        if (! in_array($type, Package::TYPES, true)) {
            throw new RuntimeException("Unknown package type {$type}.");
        }

        return DB::transaction(fn () => Package::create([
            'company_id' => $order->company_id, 'order_id' => $order->id, 'sscc' => $this->nextSscc((int) $order->company_id),
            'type' => $type, 'status' => 'OPEN', 'packed_by' => $user->id,
        ]));
    }

    /** Add a quantity of a line to a package. With a scan, the scan must be the line's item. */
    public function add(Package $package, FulfilmentLine $line, float $qty, ?int $lotId = null, ?string $scan = null): PackageContent
    {
        if ($package->status !== 'OPEN') {
            throw new RuntimeException("Package {$package->sscc} is closed; reopen it to change what is in it.");
        }
        if ($line->order_id !== $package->order_id) {
            throw new RuntimeException('That line belongs to another order.');
        }
        if ($scan !== null && trim($scan) !== '') {
            try {
                $r = $this->barcodes->resolve($scan);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('Scan: '.$e->getMessage());
            }
            if ($r['item']->id !== $line->item_id) {
                throw new RuntimeException("The scanned item is {$r['item']->code}, not this line's.");
            }
        }
        $packable = round((float) $line->qty_picked - (float) $line->qty_packed, 4);
        if ($qty <= 0 || $qty > $packable + 1e-6) {
            throw new RuntimeException("Only {$packable} of this line is picked and not yet packed.");
        }

        return DB::transaction(function () use ($package, $line, $qty, $lotId) {
            $line->update(['qty_packed' => round((float) $line->qty_packed + $qty, 4)]);

            return PackageContent::create(['company_id' => $package->company_id, 'package_id' => $package->id,
                'order_line_id' => $line->id, 'item_id' => $line->item_id, 'lot_id' => $lotId ?? $this->pickedLot($line), 'qty' => $qty]);
        });
    }

    /** Take something back out -- a repack, or a mistake caught at the check. */
    public function remove(PackageContent $content): void
    {
        $package = $content->package;
        if ($package->status === 'DISPATCHED') {
            throw new RuntimeException('That package has shipped.');
        }
        DB::transaction(function () use ($content) {
            $line = $content->line;
            $line->update(['qty_packed' => round(max(0.0, (float) $line->qty_packed - (float) $content->qty), 4)]);
            $content->delete();
        });
    }

    public function close(Package $package, array $measures = []): Package
    {
        if ($package->status !== 'OPEN') {
            throw new RuntimeException("Package {$package->sscc} is already ".strtolower($package->status).'.');
        }
        if (! $package->contents()->exists()) {
            throw new RuntimeException('An empty package cannot be closed.');
        }
        $package->update(array_intersect_key($measures, array_flip(['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'tracking_number']))
            + ['status' => 'CLOSED', 'closed_at' => now()]);
        $this->refreshOrder($package->order);

        return $package;
    }

    public function reopen(Package $package): Package
    {
        if ($package->status !== 'CLOSED') {
            throw new RuntimeException('Only a closed package that has not shipped can be reopened.');
        }
        $package->update(['status' => 'OPEN', 'closed_at' => null]);
        if ($package->order->status === 'PACKED') {
            $package->order->update(['status' => 'PICKED']);
        }

        return $package;
    }

    /**
     * Picked versus packed, line by line -- the check a packer signs off and dispatch insists on
     * when the warehouse says so.
     *
     * @return list<array{line:int, item:string, picked:float, packed:float, difference:float}>
     */
    public function check(FulfilmentOrder $order): array
    {
        return $order->lines()->with('item:id,code')->get()->map(fn (FulfilmentLine $l) => [
            'line' => $l->line_no, 'item' => $l->item?->code, 'picked' => (float) $l->qty_picked, 'packed' => (float) $l->qty_packed,
            'difference' => round((float) $l->qty_picked - (float) $l->qty_packed, 4),
        ])->all();
    }

    private function refreshOrder(FulfilmentOrder $order): void
    {
        $open = $order->packages()->where('status', 'OPEN')->exists();
        $unpacked = $order->lines()->get()->sum(fn ($l) => max(0.0, (float) $l->qty_picked - (float) $l->qty_packed));
        if (! $open && $unpacked <= 1e-6 && $order->status === 'PICKED') {
            $order->update(['status' => 'PACKED', 'packed_at' => now()]);
        }
    }

    private function pickedLot(FulfilmentLine $line): ?int
    {
        $lots = $line->allocations()->where('qty_picked', '>', 0)->pluck('lot_id')->unique();

        return $lots->count() === 1 ? $lots->first() : null;
    }

    /**
     * A GS1 SSCC: extension digit, the company prefix (config, or an internal 20+company-number
     * prefix until a real GS1 prefix is configured), a serial, and the check digit.
     */
    private function nextSscc(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-sscc:'.$companyId)]);
        $prefix = (string) (config('warehouse.gs1_company_prefix') ?: '20'.str_pad((string) $companyId, 7, '0', STR_PAD_LEFT));
        $prefix = substr(preg_replace('/\D/', '', $prefix), 0, 10);
        $serialLength = 16 - strlen($prefix);
        $last = Package::query()->where('company_id', $companyId)->where('sscc', 'like', '0'.$prefix.'%')->max('sscc');
        $next = $last ? (int) substr($last, 1 + strlen($prefix), $serialLength) + 1 : 1;
        $body = '0'.$prefix.str_pad((string) $next, $serialLength, '0', STR_PAD_LEFT);

        for ($check = 0; $check <= 9; $check++) {
            if (Gs1Parser::checkDigitValid($body.$check)) {
                return $body.$check;
            }
        }

        throw new RuntimeException('Could not compute an SSCC check digit.');
    }
}
