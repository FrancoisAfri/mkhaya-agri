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
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['harvested_on' => 'A harvest cannot be recorded for a future date.']);
        }
        if ($plan->planted_on && $date->lt($plan->planted_on)) {
            throw ValidationException::withMessages(['harvested_on' => 'A harvest cannot be before the crop was planted ('.$plan->planted_on->toDateString().').']);
        }
        if ((float) $data['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be more than zero.']);
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
                ]);
                $dims = AgriLedger::dimensions($farm, $plan->field, $plan, 'harvest');

                if ($destination === 'store') {
                    if (empty($data['stock_item_id'])) {
                        throw ValidationException::withMessages(['stock_item_id' => 'Choose the stock item the produce is stored as.']);
                    }
                    $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
                    $warehouse = $this->stores->storeFor($farm);
                    $movement = $this->stock->receive($item, (float) $data['quantity'], $value, $warehouse, [
                        'date' => $data['harvested_on'], 'reference' => "Harvest {$plan->code}",
                        'contra' => config('agri.accounts.harvest_gain'),
                        'source_type' => 'agri_harvest', 'source_id' => $record->id,
                        'idempotency_key' => "agri-harvest-{$record->uuid}", 'reason_code' => 'HARVEST',
                        'dimensions' => $dims,
                    ]);
                    $record->update(['stock_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'stock_movement_id' => $movement->id]);
                } elseif ($destination === 'sold' && $value > 0) {
                    $journal = $this->ledger->sale($farm, round($value * (float) $data['quantity'], 2), $data['harvested_on'],
                        "Harvest sale {$plan->code}", $dims, 'produce', $data['settled_by'] ?? 'receivable', "agri-harvest-sale-{$record->uuid}");
                    $record->update(['journal_id' => $journal->id]);
                }

                $this->plans->startHarvest($plan);

                return $record;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
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
