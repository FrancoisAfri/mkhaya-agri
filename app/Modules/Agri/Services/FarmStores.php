<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmTransaction;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A farm's store is a warehouse in the shared inventory (spec Module D): the same items, the same
 * balance, the same movements and journals as every other warehouse. Nothing is counted twice —
 * Mkhaya Agri only knows which warehouse is which farm's.
 */
class FarmStores
{
    /**
     * How a farm store runs in the warehouse: produce is counted, not scanned, until the farm labels
     * its bins and switches scanning on under Warehouse settings.
     */
    public const WAREHOUSE_POLICIES = ['scan_to_confirm' => false];

    public function __construct(private readonly StockLedger $ledger, private readonly AgriLedger $money) {}

    /** The farm's store, opened the first time it is needed. */
    public function storeFor(Farm $farm): FinWarehouse
    {
        if ($farm->warehouse_id) {
            $existing = FinWarehouse::withoutGlobalScopes()->where('company_id', $farm->company_id)->find($farm->warehouse_id);
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($farm) {
            $code = Str::limit('FARM-'.strtoupper($farm->code), 40, '');
            $warehouse = FinWarehouse::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('code', $code)->first()
                ?? FinWarehouse::create([
                    'company_id' => $farm->company_id, 'code' => $code, 'name' => "{$farm->name} store",
                    'address' => $farm->location, 'is_active' => true, 'is_default' => false, 'policies' => self::WAREHOUSE_POLICIES,
                ]);
            $farm->forceFill(['warehouse_id' => $warehouse->id])->saveQuietly();
            \App\Modules\Agri\Models\FarmStore::withoutGlobalScopes()->firstOrCreate(['warehouse_id' => $warehouse->id],
                ['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'kind' => 'main', 'name' => $warehouse->name]);

            return $warehouse;
        });
    }

    /**
     * Use stock from a farm store, first-expiring lot first (FEFO). Stock received in lots — a seed
     * batch, a chemical's batch number — is issued lot by lot, one movement each; stock held in one
     * place is one movement under the caller's idempotency key, exactly as before. The options are
     * StockLedger::issueTo's; `warehouse` picks a store other than the farm's main one.
     *
     * @return array{movement: FinStockMovement, movements: list<FinStockMovement>, value: float, warehouse_id: int, lots: list<?int>}
     */
    public function issue(Farm $farm, FinStockItem $item, float $qty, array $options): array
    {
        $warehouse = $options['warehouse'] ?? $this->storeFor($farm);
        $actor = $options['actor'] ?? null;
        unset($options['warehouse'], $options['actor']);
        $source = isset($options['source_type'], $options['source_id']) ? [$options['source_type'], (int) $options['source_id']] : null;
        $key = $options['idempotency_key'] ?? null;

        // A request already posted (a retry) is answered from what it posted, not picked again.
        if ($key && ($done = FinStockMovement::withoutGlobalScopes()->where('company_id', $farm->company_id)
            ->where(fn ($q) => $q->where('idempotency_key', $key)->orWhere('idempotency_key', 'like', $key.'#%'))->orderBy('id')->get())->isNotEmpty()) {
            return $this->issued($done->all(), (int) $warehouse->id);
        }
        // §5.3: a restricted chemical, or anything from a locked store, only by an authorised person.
        app(ChemicalControls::class)->assertMayUse($item, $actor, (int) $warehouse->id);
        $picks = isset($options['lot_id']) ? [['lot_id' => $options['lot_id'], 'bin_id' => $options['bin_id'] ?? null, 'qty' => $qty]]
            : $this->ledger->pick($item, (int) $warehouse->id, $qty, $source);
        $movements = [];
        foreach ($picks as $i => $p) {
            $movements[] = $this->ledger->issueTo($item, (float) $p['qty'], $warehouse, array_merge($options,
                ['lot_id' => $p['lot_id'], 'bin_id' => $p['bin_id']], $key ? ['idempotency_key' => $i === 0 ? $key : "{$key}#{$i}"] : []));
        }

        return $this->issued($movements, (int) $warehouse->id);
    }

    /** @param list<FinStockMovement> $movements */
    private function issued(array $movements, int $warehouseId): array
    {
        return ['movement' => $movements[0], 'movements' => $movements, 'value' => round(array_sum(array_map(fn ($m) => abs((float) $m->value), $movements)), 2),
            'warehouse_id' => $warehouseId, 'lots' => array_map(fn ($m) => $m->lot_id, $movements)];
    }

    public function available(FinStockItem $item, Farm $farm): float
    {
        return $farm->warehouse_id ? $this->ledger->available($item, (int) $farm->warehouse_id) : 0.0;
    }

    /**
     * Stock into the farm store: a purchase delivered straight to the farm (Cr accounts payable), or
     * an opening balance when the farm starts on the system (Cr retained earnings).
     */
    public function receive(Farm $farm, FinStockItem $item, float $qty, float $unitCost, string $date, string $basis,
        ?string $reference, User $actor, ?string $idempotencyKey = null): FinStockMovement
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be more than zero.']);
        }
        $contra = $basis === 'opening' ? config('finance.retained_earnings_account') : config('finance.ap_control');
        app(ChemicalControls::class)->assertStorable($item, (int) $this->storeFor($farm)->id);

        return $this->ledger->receive($item, $qty, $unitCost, $this->storeFor($farm), [
            'date' => $date, 'reference' => $reference ?: ($basis === 'opening' ? 'Opening balance' : 'Farm delivery'),
            'contra' => $contra, 'source_type' => 'agri_farm', 'source_id' => $farm->id,
            'idempotency_key' => $idempotencyKey, 'reason_code' => $basis === 'opening' ? 'OPENING' : 'RECEIPT',
            'dimensions' => AgriLedger::dimensions($farm, null, null, 'stock'),
        ]);
    }

    /**
     * Produce sold out of the farm store: the stock leaves at its carrying cost (Dr Cost of sales /
     * Cr Inventory) and the sale posts as farm income (Dr bank or debtors / Cr Produce or Livestock
     * sales), both with the farm's dimensions — so the margin on stored produce is the sale less what
     * it was carried at, and the stock count and the books move together.
     */
    public function sell(Farm $farm, FinStockItem $item, float $qty, float $amount, string $date, string $description,
        string $settledBy, ?Model $enterprise, ?string $buyer, User $actor): FarmTransaction
    {
        if ($qty <= 0 || $amount <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity and amount must be more than zero.']);
        }
        if (! $farm->warehouse_id) {
            throw ValidationException::withMessages(['stock_item_id' => 'This farm has no store to sell from.']);
        }
        $available = $this->ledger->available($item, (int) $farm->warehouse_id);
        if ($available + 0.0001 < $qty) {
            throw ValidationException::withMessages(['quantity' => "Only {$available} {$item->uom} of {$item->name} is in the {$farm->name} store."]);
        }

        try {
            return DB::transaction(function () use ($farm, $item, $qty, $amount, $date, $description, $settledBy, $enterprise, $buyer, $actor) {
                $category = $enterprise instanceof \App\Modules\Agri\Models\LivestockGroup ? 'livestock' : 'produce';
                $t = FarmTransaction::create([
                    'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'kind' => 'sale', 'category' => $category,
                    'enterprise' => $enterprise ? AgriLedger::enterpriseKey($enterprise) : null, 'transacted_on' => $date,
                    'description' => $description, 'counterparty' => $buyer, 'quantity' => $qty, 'unit' => $item->uom,
                    'amount' => $amount, 'settled_by' => $settledBy, 'stock_item_id' => $item->id, 'recorded_by' => $actor->id,
                ]);
                $dims = AgriLedger::dimensions($farm, null, $enterprise, $category);
                $movement = $this->ledger->dispatchToCustomer($item, $qty, $this->storeFor($farm), [
                    'source_type' => 'agri_sale', 'source_id' => $t->id, 'idempotency_key' => "agri-sale-{$t->uuid}",
                    'date' => $date, 'reference' => mb_strimwidth("Sale: {$description}", 0, 120), 'reason_code' => 'SALE',
                    'dimensions' => $dims,
                ]);
                $journal = $this->money->sale($farm, $amount, $date, $description, $dims, $category, $settledBy, "agri-sale-{$t->uuid}");
                $t->update(['stock_movement_id' => $movement->id, 'journal_id' => $journal->id]);

                return $t;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }
}
