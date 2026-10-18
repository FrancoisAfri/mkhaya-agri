<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Transfer;
use App\Modules\Operations\Manufacturing\Models\TransferLine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Several factories in one company (MFG-024): which warehouses are a factory's, which products a
 * factory may make, stock moved between factories, and where one factory's shortage can be met
 * from another's surplus. Every check of who may act is a check of the factories they may see.
 */
class MultiFactory
{
    public const SOURCE_TYPE = 'MFG_TRANSFER';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly NumberSequences $numbers,
        private readonly FactoryAccess $access,
        private readonly ManufacturingAccounting $accounting,
    ) {}

    // ── Master data and inventory per factory ────────────────────────────────────────────────

    /** @return list<int> the factory's default warehouse and every warehouse it has been given */
    public function warehousesOf(Factory $factory): array
    {
        return collect([$factory->default_warehouse_id])->merge(DB::table('mfg_factory_warehouses')->where('factory_id', $factory->id)->pluck('warehouse_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function assignWarehouse(Factory $factory, FinWarehouse $warehouse, string $role = 'GENERAL'): void
    {
        DB::table('mfg_factory_warehouses')->updateOrInsert(['factory_id' => $factory->id, 'warehouse_id' => $warehouse->id],
            ['company_id' => $factory->company_id, 'role' => $role, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function allowedAt(Item $item, Factory $factory): bool
    {
        // Read from the record, not a copy that may predate a policy change.
        $scope = Item::withoutGlobalScopes()->whereKey($item->id)->value('factory_scope') ?? 'ALL';

        return $scope === 'ALL'
            || DB::table('mfg_item_factories')->where('item_id', $item->id)->where('factory_id', $factory->id)->exists();
    }

    /** A product made everywhere, or only at the listed factories. */
    public function restrict(Item $item, string $scope, array $factoryIds, User $actor): Item
    {
        if ($scope === 'LISTED' && $factoryIds === []) {
            throw new DomainException('A product restricted to some factories needs at least one of them.');
        }
        DB::transaction(function () use ($item, $scope, $factoryIds) {
            $item->update(['factory_scope' => $scope]);
            DB::table('mfg_item_factories')->where('item_id', $item->id)->delete();
            if ($scope === 'LISTED') {
                foreach (array_unique($factoryIds) as $id) {
                    DB::table('mfg_item_factories')->insert(['company_id' => $item->company_id, 'item_id' => $item->id, 'factory_id' => (int) $id,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        activity()->causedBy($actor)->performedOn($item)->useLog('manufacturing')
            ->withProperties(['scope' => $scope, 'factories' => array_values($factoryIds)])->log('factory scope changed');

        return $item->refresh();
    }

    // ── Transfers ────────────────────────────────────────────────────────────────────────────

    /** @param  array{from: Factory, to: Factory, from_warehouse?: ?FinWarehouse, to_warehouse?: ?FinWarehouse, lines: list<array{stock_item_id: int, qty: float, lot_id?: ?int}>, needed_by?: ?string, reason?: ?string, production_order_id?: ?int}  $d */
    public function request(array $d, User $actor): Transfer
    {
        [$from, $to] = [$d['from'], $d['to']];
        if ($from->id === $to->id) {
            throw new DomainException('A transfer goes between two different factories.');
        }
        if (! $this->access->canSeeFactory($actor, $to->id)) {
            throw new DomainException("You do not work at {$to->code}, so you cannot ask for stock to go there.");
        }
        if (($d['lines'] ?? []) === []) {
            throw new DomainException('Say what is to be moved.');
        }
        $fromWh = $d['from_warehouse'] ?? FinWarehouse::query()->find($from->default_warehouse_id);
        $toWh = $d['to_warehouse'] ?? FinWarehouse::query()->find($to->default_warehouse_id);
        if (! $fromWh || ! in_array($fromWh->id, $this->warehousesOf($from), true)) {
            throw new DomainException("Choose one of {$from->code}'s warehouses to send from.");
        }
        if (! $toWh || ! in_array($toWh->id, $this->warehousesOf($to), true)) {
            throw new DomainException("Choose one of {$to->code}'s warehouses to receive into.");
        }

        return DB::transaction(function () use ($d, $from, $to, $fromWh, $toWh, $actor) {
            $t = Transfer::create(['company_id' => $from->company_id, 'number' => $this->numbers->next($from->company_id, 'TRANSFER', $to->id),
                'from_factory_id' => $from->id, 'to_factory_id' => $to->id, 'from_warehouse_id' => $fromWh->id, 'to_warehouse_id' => $toWh->id,
                'status' => Transfer::REQUESTED, 'needed_by' => $d['needed_by'] ?? null, 'reason' => $d['reason'] ?? null,
                'production_order_id' => $d['production_order_id'] ?? null, 'requested_by' => $actor->id]);
            foreach ($d['lines'] as $l) {
                if ((float) $l['qty'] <= 0) {
                    throw new DomainException('Each line moves a quantity greater than zero.');
                }
                TransferLine::create(['company_id' => $t->company_id, 'transfer_id' => $t->id, 'stock_item_id' => (int) $l['stock_item_id'],
                    'lot_id' => $l['lot_id'] ?? null, 'qty_requested' => (float) $l['qty']]);
            }

            return $t->refresh();
        });
    }

    /** The sending factory agrees to part with it: somebody who works there. */
    public function approve(Transfer $t, User $actor): Transfer
    {
        $this->assertStatus($t, [Transfer::REQUESTED], 'approved');
        if (! $this->access->canSeeFactory($actor, $t->from_factory_id)) {
            throw new DomainException('Only somebody at the sending factory can agree to send its stock.');
        }
        $t->update(['status' => Transfer::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $t->refresh();
    }

    /**
     * It leaves: each line moves to the destination warehouse, held there while in transit so it
     * cannot be used before it is received. Warehouse to warehouse: no journal.
     */
    public function ship(Transfer $t, User $actor, array $qty = []): Transfer
    {
        $this->assertStatus($t, [Transfer::APPROVED], 'shipped');
        if (! $this->access->canSeeFactory($actor, $t->from_factory_id)) {
            throw new DomainException('Only the sending factory ships.');
        }
        $this->accounting->assertAllowed($t->company_id, 'INTER_FACTORY_TRANSFER');

        return DB::transaction(function () use ($t, $actor, $qty) {
            foreach ($t->lines()->get() as $line) {
                $q = (float) ($qty[$line->id] ?? $line->qty_requested);
                if ($q <= 0) {
                    continue;
                }
                $item = FinStockItem::withoutGlobalScopes()->findOrFail($line->stock_item_id);
                $free = $this->stock->available($item, $t->from_warehouse_id, $line->lot_id);
                if ($free + 0.0001 < $q) {
                    throw new DomainException(sprintf('Only %s of %s is free to send.', rtrim(rtrim(number_format($free, 4, '.', ''), '0'), '.'), $item->code));
                }
                $m = $this->stock->transfer($item, $t->fromWarehouse()->firstOrFail(), $t->toWarehouse()->firstOrFail(), $q, [
                    'lot_id' => $line->lot_id, 'to_status' => Transfer::IN_TRANSIT_STATUS, 'reference' => $t->number,
                    'source_type' => self::SOURCE_TYPE, 'source_id' => $t->id, 'idempotency_key' => "mfg-transfer:{$t->id}:{$line->id}",
                ]);
                $line->update(['qty_shipped' => $q, 'shipment_movement_id' => $m->id]);
            }
            $t->update(['status' => Transfer::SHIPPED, 'shipped_by' => $actor->id, 'shipped_at' => now()]);

            return $t->refresh();
        });
    }

    /** It arrives: what was counted is released for use; anything short stays held and is noted. */
    public function receive(Transfer $t, User $actor, array $qty = [], ?string $discrepancy = null): Transfer
    {
        $this->assertStatus($t, [Transfer::SHIPPED], 'received');
        if (! $this->access->canSeeFactory($actor, $t->to_factory_id)) {
            throw new DomainException('Only the receiving factory receives.');
        }

        return DB::transaction(function () use ($t, $actor, $qty, $discrepancy) {
            $short = [];
            foreach ($t->lines()->get() as $line) {
                $q = (float) ($qty[$line->id] ?? $line->qty_shipped);
                if ($q < 0 || $q > $line->qty_shipped + 0.0001) {
                    throw new DomainException('Receive no more than was shipped.');
                }
                if ($q > 0) {
                    $item = FinStockItem::withoutGlobalScopes()->findOrFail($line->stock_item_id);
                    $this->stock->changeStatus($item, $t->toWarehouse()->firstOrFail(), $q, Transfer::IN_TRANSIT_STATUS, FinStockPosition::AVAILABLE, [
                        'lot_id' => $line->lot_id, 'reason' => "Received on {$t->number}", 'idempotency_key' => "mfg-transfer-in:{$t->id}:{$line->id}",
                    ]);
                }
                $line->update(['qty_received' => $q]);
                if ($q + 0.0001 < $line->qty_shipped) {
                    $short[] = $line->stockItem?->code.' '.rtrim(rtrim(number_format($line->qty_shipped - $q, 4, '.', ''), '0'), '.');
                }
            }
            if ($short && blank($discrepancy)) {
                throw new DomainException('Less arrived than was shipped ('.implode(', ', $short).'). Say what happened; the difference stays on hold.');
            }
            $t->update(['status' => Transfer::RECEIVED, 'received_by' => $actor->id, 'received_at' => now(),
                'discrepancy' => $short ? 'Short: '.implode(', ', $short).'. '.$discrepancy : null]);

            return $t->refresh();
        });
    }

    public function cancel(Transfer $t, User $actor, string $reason): Transfer
    {
        $this->assertStatus($t, [Transfer::REQUESTED, Transfer::APPROVED], 'cancelled');
        if (blank($reason)) {
            throw new DomainException('Say why.');
        }
        $t->update(['status' => Transfer::CANCELLED, 'reason' => trim(($t->reason ? $t->reason."\n" : '').'Cancelled: '.$reason)]);

        return $t->refresh();
    }

    // ── Cross-factory planning ───────────────────────────────────────────────────────────────

    /**
     * Each factory's outstanding material need against what its own warehouses hold, and where a
     * shortage at one factory could be met from another's surplus.
     *
     * @return array{rows: list<array>, suggestions: list<array>}
     */
    public function balances(): array
    {
        $factories = Factory::query()->where('is_active', true)->get()->keyBy('id');
        $need = DB::table('mfg_production_order_components as c')->join('mfg_production_orders as o', 'o.id', '=', 'c.order_id')
            ->whereIn('o.status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])->whereNotNull('c.stock_item_id')->where('c.line_kind', '!=', 'BY_PRODUCT')
            ->groupBy('o.factory_id', 'c.stock_item_id')
            ->selectRaw('o.factory_id, c.stock_item_id, SUM(GREATEST(c.required_qty - c.issued_qty + c.returned_qty, 0)) AS need')->get();
        $items = $need->pluck('stock_item_id')->unique();
        $rows = [];
        foreach ($items as $itemId) {
            $item = FinStockItem::query()->find($itemId);
            if (! $item) {
                continue;
            }
            foreach ($factories as $f) {
                $have = 0.0;
                foreach ($this->warehousesOf($f) as $wh) {
                    $have += $this->stock->available($item, $wh);
                }
                $n = (float) ($need->where('factory_id', $f->id)->where('stock_item_id', $itemId)->first()->need ?? 0);
                if ($n <= 0 && $have <= 0) {
                    continue;
                }
                $rows[] = ['item' => $item->code, 'stock_item_id' => $item->id, 'factory' => $f->code, 'factory_id' => $f->id,
                    'need' => round($n, 4), 'available' => round($have, 4), 'balance' => round($have - $n, 4)];
            }
        }
        $suggestions = [];
        foreach (collect($rows)->groupBy('stock_item_id') as $itemRows) {
            $surplus = $itemRows->filter(fn ($r) => $r['balance'] > 0)->sortByDesc('balance')->values()->all();
            foreach ($itemRows->filter(fn ($r) => $r['balance'] < 0) as $short) {
                $gap = -$short['balance'];
                foreach ($surplus as &$s) {
                    if ($gap <= 0.0001 || $s['balance'] <= 0.0001) {
                        continue;
                    }
                    $move = min($gap, $s['balance']);
                    $suggestions[] = ['item' => $short['item'], 'stock_item_id' => $short['stock_item_id'], 'from' => $s['factory'], 'from_id' => $s['factory_id'],
                        'to' => $short['factory'], 'to_id' => $short['factory_id'], 'qty' => round($move, 4)];
                    $s['balance'] -= $move;
                    $gap -= $move;
                }
                unset($s);
            }
        }

        return ['rows' => $rows, 'suggestions' => $suggestions];
    }

    private function assertStatus(Transfer $t, array $allowed, string $verb): void
    {
        if (! in_array($t->status, $allowed, true)) {
            throw new DomainException("{$t->number} is ".strtolower($t->status)." and cannot be {$verb}.");
        }
    }
}
