<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\SupplierReturn;
use App\Modules\Operations\WarehouseManagement\Models\SupplierReturnLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Returns to suppliers (WMS-035): DRAFT → APPROVED → DISPATCHED → ACKNOWLEDGED → CLOSED.
 *
 * Stock leaves at dispatch, not at approval: until the vehicle goes, the goods are still on the
 * floor and must still be counted. They leave from whatever state they are held in -- rejected,
 * quarantined, damaged -- through the ledger's return-to-supplier movement, which reverses the
 * Goods Received Not Invoiced accrual at cost. The credit or replacement that follows is recorded
 * against the return; the credit note itself is Finance's document.
 */
class SupplierReturns
{
    public function __construct(private readonly StockLedger $ledger) {}

    /** @param list<array{item_id:int, qty:float, bin_id?:?int, lot_id?:?int, stock_status?:string, serial_numbers?:list<string>, grn_item_id?:?int}> $lines */
    public function draft(FinWarehouse $warehouse, int $supplierId, string $reason, array $lines, User $user, ?int $grnId = null): SupplierReturn
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the goods are going back.');
        }
        if ($lines === []) {
            throw new RuntimeException('Add at least one line.');
        }

        return DB::transaction(function () use ($warehouse, $supplierId, $reason, $lines, $user, $grnId) {
            $return = SupplierReturn::create([
                'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id),
                'supplier_id' => $supplierId, 'warehouse_id' => $warehouse->id, 'grn_id' => $grnId, 'status' => 'DRAFT',
                'reason' => $reason, 'created_by' => $user->id,
            ]);
            foreach ($lines as $l) {
                if ((float) $l['qty'] <= 0) {
                    throw new RuntimeException('Every line needs a quantity above zero.');
                }
                $this->assertAvailableToReturn($warehouse, $l);
                $return->lines()->create([
                    'company_id' => $warehouse->company_id, 'item_id' => $l['item_id'], 'qty' => $l['qty'],
                    'bin_id' => $l['bin_id'] ?? null, 'lot_id' => $l['lot_id'] ?? null,
                    'stock_status' => $l['stock_status'] ?? FinStockPosition::REJECTED,
                    'serial_numbers' => $l['serial_numbers'] ?? null, 'grn_item_id' => $l['grn_item_id'] ?? null,
                ]);
            }

            return $return->fresh('lines');
        });
    }

    public function approve(SupplierReturn $return, User $user): SupplierReturn
    {
        $this->assertStatus($return, 'DRAFT');
        $return->update(['status' => 'APPROVED', 'approved_by' => $user->id]);

        return $return;
    }

    public function dispatch(SupplierReturn $return, User $user, ?string $carrier, ?string $reference): SupplierReturn
    {
        $this->assertStatus($return, 'APPROVED');

        return DB::transaction(function () use ($return, $carrier, $reference) {
            foreach ($return->lines as $line) {
                $movement = $this->ledger->returnToSupplier(FinStockItem::query()->findOrFail($line->item_id), (float) $line->qty, $return->warehouse, [
                    'bin_id' => $line->bin_id, 'lot_id' => $line->lot_id, 'status' => $line->stock_status,
                    'source_type' => 'SUPPLIER_RETURN', 'source_id' => $return->id, 'reference' => $return->number,
                    'reason_code' => 'SUPPLIER_RETURN', 'idempotency_key' => "supplier-return:{$return->id}:line:{$line->id}",
                ]);
                $line->update(['movement_id' => $movement->id]);
            }
            $return->update(['status' => 'DISPATCHED', 'carrier' => $carrier, 'dispatch_reference' => $reference, 'dispatched_at' => now()]);

            return $return;
        });
    }

    public function acknowledge(SupplierReturn $return, string $byName): SupplierReturn
    {
        $this->assertStatus($return, 'DISPATCHED');
        if (trim($byName) === '') {
            throw new RuntimeException('Record who at the supplier acknowledged the return.');
        }
        $return->update(['status' => 'ACKNOWLEDGED', 'acknowledged_at' => now(), 'acknowledged_by_name' => $byName]);

        return $return;
    }

    public function close(SupplierReturn $return, string $outcome, ?string $creditReference): SupplierReturn
    {
        $this->assertStatus($return, 'ACKNOWLEDGED');
        if (! in_array($outcome, ['REPLACEMENT', 'CREDIT', 'NONE'], true)) {
            throw new RuntimeException('The outcome is a replacement, a credit or nothing.');
        }
        if ($outcome === 'CREDIT' && blank($creditReference)) {
            throw new RuntimeException('Record the supplier\'s credit note number.');
        }
        $return->update(['status' => 'CLOSED', 'outcome' => $outcome, 'credit_reference' => $creditReference]);

        return $return;
    }

    public function cancel(SupplierReturn $return): SupplierReturn
    {
        if (! in_array($return->status, ['DRAFT', 'APPROVED'], true)) {
            throw new RuntimeException('A return can only be cancelled before it is dispatched; after that, receive the goods back.');
        }
        $return->update(['status' => 'CANCELLED']);

        return $return;
    }

    private function assertAvailableToReturn(FinWarehouse $warehouse, array $l): void
    {
        $there = (float) FinStockPosition::query()->where('item_id', $l['item_id'])->where('warehouse_id', $warehouse->id)
            ->where('status', $l['stock_status'] ?? FinStockPosition::REJECTED)
            ->when($l['bin_id'] ?? null, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
            ->when($l['lot_id'] ?? null, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))
            ->value('qty');
        if ((float) $l['qty'] > $there + 1e-6) {
            throw new RuntimeException("Only {$there} is held in that place and state to return.");
        }
    }

    private function assertStatus(SupplierReturn $return, string $expected): void
    {
        if ($return->status !== $expected) {
            throw new RuntimeException("{$return->number} is ".strtolower($return->status).'.');
        }
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-sret-number:'.$companyId)]);
        $prefix = 'RTS-'.now()->format('Y').'-';
        $last = SupplierReturn::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
