<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corrections to stock (WMS-024): every one has a reason code, and the risky ones wait for a
 * second person.
 *
 * Risk is judged on what the adjustment would do, not on who asks: a reason code that always
 * needs approval (theft, write-off), a quantity or value above the code's limits, or a date
 * further back than the warehouse allows. Anything else posts at once -- a dropped box should not
 * queue behind a manager -- and every one, approved or not, carries its reason onto the movement.
 *
 * A period Finance has closed cannot be adjusted into at all; that is Finance's lock, read here
 * so the person is told before they fill in the form a second time.
 */
class StockAdjustments
{
    public const DEFAULT_MAX_BACKDATE_DAYS = 7;

    public function __construct(private readonly StockLedger $ledger, private readonly UnitQuantity $units) {}

    public function ensureReasonCodes(int $companyId): void
    {
        foreach (ReasonCode::DEFAULTS as $row) {
            [$code, $name, $category, $direction, $qty, $value, $always] = $row;
            ReasonCode::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], [
                'name' => $name, 'category' => $category, 'direction' => $direction, 'approval_qty' => $qty,
                'approval_value' => $value, 'always_requires_approval' => $always, 'is_active' => true,
                'requires_evidence' => $row[7] ?? false,
            ]);
        }
    }

    /** @param array{warehouse_id:int,item_id:int,bin_id?:?int,lot_id?:?int,stock_status?:string,qty:float,uom_id?:?int,reason_code_id:int,notes?:?string,occurred_at?:?string} $data */
    public function request(array $data, User $requester): StockAdjustment
    {
        $warehouse = FinWarehouse::query()->findOrFail($data['warehouse_id']);
        $item = FinStockItem::query()->findOrFail($data['item_id']);
        $reason = ReasonCode::query()->where('is_active', true)->findOrFail($data['reason_code_id']);
        $bin = isset($data['bin_id']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->findOrFail($data['bin_id']) : null;
        $lot = isset($data['lot_id']) ? FinStockLot::query()->where('item_id', $item->id)->findOrFail($data['lot_id']) : null;
        $status = $data['stock_status'] ?? FinStockPosition::AVAILABLE;
        $occurred = Carbon::parse($data['occurred_at'] ?? now());

        $n = $this->units->normalise($item, (float) $data['qty'], $data['uom_id'] ?? null);
        $delta = $n['base_qty'];
        if (abs($delta) < 0.0001) {
            throw new RuntimeException('An adjustment must change the quantity.');
        }
        if (($reason->direction === 'IN' && $delta < 0) || ($reason->direction === 'OUT' && $delta > 0)) {
            throw new RuntimeException("{$reason->name} can only ".($reason->direction === 'IN' ? 'add' : 'remove').' stock.');
        }
        $files = array_values(array_filter($data['evidence'] ?? []));
        if ($reason->requires_evidence && $files === []) {
            throw new RuntimeException("{$reason->name} needs a photograph or document as evidence.");
        }
        if ($occurred->isFuture()) {
            throw new RuntimeException('An adjustment cannot be dated in the future.');
        }
        $period = FinFiscalPeriod::forDate($occurred, $item->company_id);
        if ($period !== null && ! $period->acceptsPostings()) {
            throw new RuntimeException("The {$occurred->format('F Y')} period is closed in Finance; date the adjustment in an open period.");
        }
        if ($delta < 0) {
            $there = (float) FinStockPosition::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)
                ->where('status', $status)
                ->when($bin, fn ($q) => $q->where('bin_id', $bin->id), fn ($q) => $q->whereNull('bin_id'))
                ->when($lot, fn ($q) => $q->where('lot_id', $lot->id), fn ($q) => $q->whereNull('lot_id'))
                ->value('qty');
            if (-$delta > $there + 0.0001) {
                throw new RuntimeException("Only {$there} {$item->uom} of {$item->code} is there in that state; {$n['base_qty']} cannot be removed.");
            }
        }

        $unitCost = $item->isValued() ? ((float) $item->avg_cost ?: (float) $item->standard_cost) : 0.0;
        $value = round(abs($delta) * $unitCost, 2);
        $why = $this->approvalReasons($reason, abs($delta), $value, $occurred, $warehouse);
        // High-risk stock (WMS-081): a loss of it is always looked at by a second person.
        if ($delta < 0 && $reason->approve_high_risk && ItemProfile::query()->where('stock_item_id', $item->id)->value('loss_risk') === 'HIGH') {
            $why[] = 'high-risk item';
        }

        return DB::transaction(function () use ($warehouse, $item, $bin, $lot, $status, $n, $delta, $unitCost, $value, $reason, $data, $occurred, $requester, $why, $files) {
            $adjustment = StockAdjustment::create([
                'company_id' => $item->company_id, 'number' => $this->nextNumber((int) $item->company_id),
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'bin_id' => $bin?->id, 'lot_id' => $lot?->id,
                'stock_status' => $status, 'qty_delta' => $delta, 'txn_uom_id' => $n['txn_uom_id'], 'txn_qty' => $n['txn_qty'],
                'unit_cost' => $unitCost, 'value_estimate' => $value, 'reason_code_id' => $reason->id,
                'notes' => $data['notes'] ?? null, 'occurred_at' => $occurred, 'status' => StockAdjustment::PENDING,
                'approval_reasons' => $why, 'requested_by' => $requester->id,
            ]);

            foreach ($files as $file) {
                app(EvidenceLocker::class)->attach('ADJUSTMENT', $adjustment->id, (int) $item->company_id, $file, $requester);
            }
            if ($why === []) {
                $this->post($adjustment, null);
            } else {
                // The levels this one passes through, if the company has an approval policy (WMS-191).
                app(WarehouseApprovals::class)->start((int) $item->company_id, 'ADJUSTMENT', $adjustment->id, $adjustment->number, abs((float) $value), $requester->id);
            }

            return $adjustment->fresh();
        });
    }

    public function approve(StockAdjustment $adjustment, User $approver, ?string $note = null): StockAdjustment
    {
        $this->assertPending($adjustment);
        if ((int) $adjustment->requested_by === $approver->id && ! app(WarehouseOverrides::class)->active($approver, 'ADJUSTMENT')) {
            throw new RuntimeException('An adjustment must be approved by somebody other than the person who raised it.');
        }

        return DB::transaction(function () use ($adjustment, $approver, $note) {
            // Every level of the company's policy approves before the stock moves; without one, this approval is enough.
            if (! app(WarehouseApprovals::class)->approve((int) $adjustment->company_id, 'ADJUSTMENT', $adjustment->id, $adjustment->number,
                abs((float) $adjustment->value_estimate), (int) $adjustment->requested_by, $approver, $note)) {
                return $adjustment->fresh();
            }
            $override = (int) $adjustment->requested_by === $approver->id ? app(WarehouseOverrides::class)->active($approver, 'ADJUSTMENT') : null;
            $adjustment->update(['decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note, 'override_id' => $override?->id]);
            $this->post($adjustment, $approver);

            return $adjustment->fresh();
        });
    }

    public function reject(StockAdjustment $adjustment, User $approver, string $note): StockAdjustment
    {
        $this->assertPending($adjustment);
        if ((int) $adjustment->requested_by === $approver->id) {
            throw new RuntimeException('Withdraw your own adjustment instead of rejecting it.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Say why the adjustment is rejected.');
        }
        return DB::transaction(function () use ($adjustment, $approver, $note) {
            app(WarehouseApprovals::class)->reject('ADJUSTMENT', $adjustment->id, (int) $adjustment->requested_by, $approver, $note);
            $adjustment->update(['status' => StockAdjustment::REJECTED, 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $adjustment;
        });
    }

    public function cancel(StockAdjustment $adjustment, User $requester): StockAdjustment
    {
        $this->assertPending($adjustment);
        if ((int) $adjustment->requested_by !== $requester->id) {
            throw new RuntimeException('Only the person who raised an adjustment can withdraw it.');
        }
        $adjustment->update(['status' => StockAdjustment::CANCELLED, 'decided_at' => now()]);
        app(WarehouseApprovals::class)->close('ADJUSTMENT', $adjustment->id);

        return $adjustment;
    }

    /** @return list<string> */
    private function approvalReasons(ReasonCode $reason, float $qty, float $value, Carbon $occurred, FinWarehouse $warehouse): array
    {
        $why = [];
        if ($reason->always_requires_approval) {
            $why[] = "{$reason->name} always needs approval";
        }
        if ($reason->approval_qty !== null && $qty > (float) $reason->approval_qty) {
            $why[] = "quantity above {$reason->approval_qty}";
        }
        if ($reason->approval_value !== null && $value > (float) $reason->approval_value) {
            $why[] = 'value above '.number_format((float) $reason->approval_value, 2);
        }
        $maxDays = (int) ($warehouse->policies['max_backdate_days'] ?? self::DEFAULT_MAX_BACKDATE_DAYS);
        if ($occurred->lt(now()->subDays($maxDays)->startOfDay())) {
            $why[] = "dated more than {$maxDays} days back";
        }

        return $why;
    }

    private function post(StockAdjustment $adjustment, ?User $approver): void
    {
        $item = FinStockItem::query()->findOrFail($adjustment->item_id);
        $reason = $adjustment->reason;

        $accounting = app(WarehouseAccounting::class);
        $accounting->assertAllowed((int) $item->company_id, 'ADJUSTMENT');
        $movement = $this->ledger->adjust($item, $adjustment->warehouse, (float) $adjustment->qty_delta, (float) $adjustment->unit_cost, [
            'contra' => $accounting->code('adjustment'),
            'reason' => trim("{$reason->name}. ".($adjustment->notes ?? '')),
            'date' => $adjustment->occurred_at->toDateString(),
            'bin_id' => $adjustment->bin_id, 'lot_id' => $adjustment->lot_id, 'status' => $adjustment->stock_status,
            'source_type' => 'WMS_ADJUSTMENT', 'source_id' => $adjustment->id,
            'idempotency_key' => 'wms-adjustment-'.$adjustment->uuid,
            'reason_code' => $reason->code, 'txn_uom_id' => $adjustment->txn_uom_id, 'txn_qty' => $adjustment->txn_qty,
            'occurred_at' => $adjustment->occurred_at, 'approved_by' => $approver?->id,
            'approval_reference' => $approver ? $adjustment->number : null,
        ]);

        $adjustment->update(['status' => StockAdjustment::POSTED, 'movement_id' => $movement->id]);
    }

    private function assertPending(StockAdjustment $adjustment): void
    {
        if ($adjustment->status !== StockAdjustment::PENDING) {
            throw new RuntimeException("{$adjustment->number} is ".strtolower(str_replace('_', ' ', $adjustment->status)).' and can no longer be decided.');
        }
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-adjustment-number:'.$companyId)]);
        $prefix = 'ADJ-'.now()->format('Y').'-';
        $last = StockAdjustment::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
