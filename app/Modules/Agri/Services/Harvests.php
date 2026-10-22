<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Harvest recording (FR-CROP harvest): what came off which field, where it went, and yield
 * against plan.
 *
 * A harvest inside a chemical withholding period is refused — produce sprayed too recently is not
 * fit to leave the field — unless a person allowed to approve crop work overrides it with a reason,
 * which stays on the record.
 *
 * Produce put into the farm store becomes stock in the shared inventory at the value given (IAS 41:
 * fair value at harvest); produce sold off the field posts the sale to the shared ledger.
 */
class Harvests
{
    public function __construct(
        private readonly CropPlanService $plans,
        private readonly FieldWork $work,
        private readonly FarmStores $stores,
        private readonly StockLedger $stock,
        private readonly AgriLedger $ledger,
    ) {}

    public function record(CropPlan $plan, array $data, User $actor): HarvestRecord
    {
        if (! in_array($plan->status, [CropPlan::PLANTED, CropPlan::HARVESTING], true)) {
            throw ValidationException::withMessages(['crop_plan_id' => "Plan {$plan->code} is {$plan->status}; only a planted crop can be harvested."]);
        }
        $date = Carbon::parse($data['harvested_on']);
        // "Today" is the farm's own date: a load weighed at 00:30 in Bothaville is today's, not tomorrow's in UTC.
        if ($date->toDateString() > \App\Modules\Agri\Support\FarmTime::today($plan->farm_id)->toDateString()) {
            throw ValidationException::withMessages(['harvested_on' => 'A harvest cannot be recorded for a future date.']);
        }
        if ($plan->planted_on && $date->lt($plan->planted_on)) {
            throw ValidationException::withMessages(['harvested_on' => 'A harvest cannot be before the crop was planted ('.$plan->planted_on->toDateString().').']);
        }
        if ((float) $data['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be more than zero.']);
        }
        $lost = (float) ($data['rejected_quantity'] ?? 0) + (float) ($data['spoiled_quantity'] ?? 0);
        if ($lost > (float) $data['quantity'] + 0.0005) {
            throw ValidationException::withMessages(['rejected_quantity' => 'Rejected and spoiled cannot be more than was harvested.']);
        }

        $until = $this->work->withholdingUntil($plan);
        $override = trim((string) ($data['override_reason'] ?? ''));
        if ($until && $date->lt($until)) {
            if ($override === '' || ! $actor->can(RoleCatalog::APPROVE_CROPS)) {
                throw ValidationException::withMessages(['harvested_on' => "This crop is inside a chemical withholding period until {$until->toDateString()}. ".
                    'Only somebody who approves crop work may override it, with a reason.']);
            }
        } else {
            $override = '';
        }

        $destination = $data['destination'] ?? 'store';
        $farm = $plan->farm;
        $value = (float) ($data['unit_value'] ?? 0);

        try {
            return DB::transaction(function () use ($plan, $data, $actor, $destination, $farm, $value, $override) {
                $record = HarvestRecord::create([
                    'company_id' => $plan->company_id, 'farm_id' => $plan->farm_id, 'crop_plan_id' => $plan->id,
                    'field_id' => $plan->field_id, 'harvested_on' => $data['harvested_on'],
                    'quantity' => $data['quantity'], 'unit' => $data['unit'] ?? $plan->yield_unit,
                    'grade' => $data['grade'] ?? null, 'moisture_pct' => $data['moisture_pct'] ?? null,
                    'area_harvested' => $data['area_harvested'] ?? null, 'destination' => $destination,
                    'unit_value' => $value ?: null, 'override_reason' => $override ?: null,
                    'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id,
                    // §3.5: the lot this harvest is traced by, and what did not make it.
                    'lot_code' => $data['lot_code'] ?? $this->nextLot($plan, $data['harvested_on']),
                    'rejected_quantity' => $data['rejected_quantity'] ?? null, 'spoiled_quantity' => $data['spoiled_quantity'] ?? null,
                    'field_loss_quantity' => $data['field_loss_quantity'] ?? null, 'containers' => $data['containers'] ?? null,
                    // §8.1: who brought it in, how it was measured, what the inspection found, where it went.
                    'crew_id' => $data['crew_id'] ?? null, 'measuring_method' => $data['measuring_method'] ?? null,
                    'inspection_outcome' => $data['inspection_outcome'] ?? null, 'inspection_note' => $data['inspection_note'] ?? null,
                    'inspected_by' => ! empty($data['inspection_outcome']) ? $actor->id : null, 'vehicle_ref' => $data['vehicle_ref'] ?? null,
                    'container_ref' => $data['container_ref'] ?? null, 'storage_location' => $data['storage_location'] ?? null,
                ]);
                $dims = AgriLedger::dimensions($farm, $plan->field, $plan, 'harvest');

                if ($destination === 'store') {
                    if (empty($data['stock_item_id'])) {
                        throw ValidationException::withMessages(['stock_item_id' => 'Choose the stock item the produce is stored as.']);
                    }
                    $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
                    // Into the silo, cold room or other store named, else the farm's main store.
                    $store = ! empty($data['store_id']) ? \App\Modules\Agri\Models\FarmStore::query()->where('farm_id', $farm->id)
                        ->where(is_numeric($data['store_id']) ? 'id' : 'uuid', $data['store_id'])->firstOrFail() : null;
                    $warehouse = $store ? \App\Modules\Finance\Accounting\Models\FinWarehouse::withoutGlobalScopes()->findOrFail($store->warehouse_id) : $this->stores->storeFor($farm);
                    // Only the sound produce goes into the store; rejects and spoilage are recorded on the harvest.
                    $sound = round((float) $data['quantity'] - (float) ($data['rejected_quantity'] ?? 0) - (float) ($data['spoiled_quantity'] ?? 0), 4);
                    if ($sound <= 0) {
                        throw ValidationException::withMessages(['rejected_quantity' => 'Nothing sound is left to put into the store; record it as a loss.']);
                    }
                    // The harvest lot is a stock lot: the warehouse holds, picks, holds back and recalls it by its code.
                    $lot = $this->stock->lot($item, $record->lot_code ?: "H-{$record->id}", ['kind' => 'LOT', 'origin' => 'RECEIPT',
                        'manufactured_on' => $data['harvested_on'], 'source_type' => 'agri_harvest', 'source_id' => $record->id]);
                    $movement = $this->stock->receive($item, $sound, $value, $warehouse, [
                        'lot_id' => $lot->id,
                        'date' => $data['harvested_on'], 'reference' => "Harvest {$plan->code}",
                        'contra' => config('agri.accounts.harvest_gain'),
                        'source_type' => 'agri_harvest', 'source_id' => $record->id,
                        'idempotency_key' => "agri-harvest-{$record->uuid}", 'reason_code' => 'HARVEST',
                        'dimensions' => $dims,
                    ]);
                    $record->update(['stock_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'stock_movement_id' => $movement->id, 'store_id' => $store?->id]);
                } elseif ($destination === 'sold' && $value > 0) {
                    $journal = $this->ledger->sale($farm, round($value * (float) $data['quantity'], 2), $data['harvested_on'],
                        "Harvest sale {$plan->code}", $dims, 'produce', $data['settled_by'] ?? 'receivable', "agri-harvest-sale-{$record->uuid}");
                    $record->update(['journal_id' => $journal->id]);
                }

                app(PostHarvest::class)->lotFromHarvest($record->fresh());
                $this->plans->startHarvest($plan);
                if (! empty($data['commitment_id'])) {
                    $c = \App\Modules\Agri\Models\HarvestCommitment::query()->where(is_numeric($data['commitment_id']) ? 'id' : 'uuid', $data['commitment_id'])
                        ->where('farm_id', $plan->farm_id)->firstOrFail();
                    app(HarvestPlanning::class)->deliver($c, $record);
                }

                return $record;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    /** PLANCODE-YYYYMMDD-n: unique per plan and day, readable on a bin label. */
    private function nextLot(CropPlan $plan, string $date): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $plan->code)).'-'.Carbon::parse($date)->format('Ymd');
        $n = HarvestRecord::query()->where('crop_plan_id', $plan->id)->where('lot_code', 'like', $prefix.'-%')->count() + 1;

        return "{$prefix}-{$n}";
    }

    /** Yield achieved against plan, per hectare, in the plan's unit. */
    public function yieldFor(CropPlan $plan): array
    {
        $harvests = $plan->harvests()->where('destination', '!=', 'loss')->get();
        $qty = (float) $harvests->sum('quantity');
        $ha = $plan->hectares();
        $actualPerHa = $ha > 0 ? round($qty / $ha, 3) : null;
        $expected = $plan->expected_yield !== null ? (float) $plan->expected_yield : null;

        return [
            'harvested' => round($qty, 3),
            'unit' => $plan->yield_unit,
            'hectares' => round($ha, 4),
            'per_ha' => $actualPerHa,
            'expected_per_ha' => $expected,
            'expected_total' => $expected !== null ? round($expected * $ha, 3) : null,
            'variance_pct' => ($expected && $actualPerHa !== null) ? round(($actualPerHa - $expected) / $expected * 100, 1) : null,
        ];
    }
}
