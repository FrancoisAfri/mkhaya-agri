<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Field activities and the inputs they use (spec Module B / D).
 *
 * Inputs are planned on the activity and issued from the farm store when the activity is completed,
 * all in one transaction: either the work is recorded with every input it consumed, or nothing is.
 * Each issue goes through the shared stock ledger with an idempotency key per input, so a
 * double-submitted "complete" cannot consume the fertiliser twice.
 */
class FieldWork
{
    public function __construct(
        private readonly FarmRegistry $registry,
        private readonly FarmStores $stores,
        private readonly StockLedger $stock,
    ) {}

    public function create(Farm $farm, ?FarmField $field, ?CropPlan $plan, ?LivestockGroup $group, array $data, User $actor): FieldActivity
    {
        $this->registry->assertOperational($farm);

        if ($plan) {
            if ((int) $plan->farm_id !== (int) $farm->id) {
                throw ValidationException::withMessages(['crop_plan_id' => 'That crop plan is not on this farm.']);
            }
            if (! in_array($plan->status, CropPlan::WORKABLE, true)) {
                throw ValidationException::withMessages(['crop_plan_id' => "Plan {$plan->code} is {$plan->status}; work is recorded only on approved, planted or harvesting plans."]);
            }
            $field ??= $plan->field;
            if ((int) $field->id !== (int) $plan->field_id) {
                throw ValidationException::withMessages(['field_id' => "Plan {$plan->code} is on field {$plan->field->code}."]);
            }
        }
        if ($group && (int) $group->farm_id !== (int) $farm->id) {
            throw ValidationException::withMessages(['livestock_group_id' => 'That group is not on this farm.']);
        }

        return FieldActivity::create($data + [
            'company_id' => $farm->company_id,
            'farm_id' => $farm->id,
            'field_id' => $field?->id,
            'crop_plan_id' => $plan?->id,
            'livestock_group_id' => $group?->id,
            'status' => FieldActivity::PLANNED,
            'created_by' => $actor->id,
        ]);
    }

    public function addInput(FieldActivity $activity, FinStockItem $item, array $data): ActivityInput
    {
        $this->assertOpen($activity);
        if ((float) $data['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be more than zero.']);
        }

        return ActivityInput::create([
            'company_id' => $activity->company_id,
            'farm_id' => $activity->farm_id,
            'activity_id' => $activity->id,
            'stock_item_id' => $item->id,
            'quantity' => $data['quantity'],
            'uom' => $item->uom,
            'rate_per_ha' => $data['rate_per_ha'] ?? null,
            'withholding_days' => $data['withholding_days'] ?? null,
            'reentry_hours' => $data['reentry_hours'] ?? null,
            'status' => ActivityInput::PLANNED,
        ]);
    }

    public function removeInput(ActivityInput $input): void
    {
        if ($input->status !== ActivityInput::PLANNED) {
            throw ValidationException::withMessages(['input' => 'An issued input is part of the stock record and cannot be removed.']);
        }
        $input->delete();
    }

    public function start(FieldActivity $activity): FieldActivity
    {
        $this->assertOpen($activity);
        $activity->update(['status' => FieldActivity::IN_PROGRESS]);

        return $activity;
    }

    /**
     * Complete the work: record what was done and issue every planned input from the farm store.
     */
    public function complete(FieldActivity $activity, array $data, User $actor): FieldActivity
    {
        $this->assertOpen($activity);
        $completedAt = Carbon::parse($data['completed_at'] ?? now());
        if ($completedAt->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['completed_at' => 'Work cannot be completed in the future.']);
        }

        $farm = $activity->farm;
        $this->registry->assertOperational($farm);

        try {
            return DB::transaction(function () use ($activity, $data, $actor, $completedAt, $farm) {
                $inputs = $activity->inputs()->where('status', ActivityInput::PLANNED)->get();
                if ($inputs->isNotEmpty()) {
                    $warehouse = $this->stores->storeFor($farm);
                    $plan = $activity->cropPlan;
                    $enterprise = $plan ?? $activity->livestockGroup;
                    $account = $activity->livestock_group_id ? config('agri.accounts.animal_health') : config('agri.accounts.inputs');

                    foreach ($inputs as $input) {
                        $item = FinStockItem::withoutGlobalScopes()->findOrFail($input->stock_item_id);
                        $issued = $this->stores->issue($farm, $item, (float) $input->quantity, [
                            'warehouse' => $warehouse, 'source_type' => 'agri_activity', 'source_id' => $activity->id,
                            'idempotency_key' => "agri-input-{$input->uuid}",
                            'date' => $completedAt->toDateString(),
                            'reference' => "{$farm->code} {$activity->title}",
                            'expense_account' => $account,
                            'reason_code' => 'AGRI_USE', 'actor' => $actor,
                            'dimensions' => AgriLedger::dimensions($farm, $activity->field, $enterprise, 'inputs'),
                        ]);
                        $movement = $issued['movement'];
                        $total = $issued['value'];
                        $input->update([
                            'status' => ActivityInput::ISSUED,
                            'warehouse_id' => $warehouse->id,
                            'stock_movement_id' => $movement->id,
                            'total_cost' => $total,
                            'unit_cost' => (float) $input->quantity > 0 ? round($total / (float) $input->quantity, 4) : 0,
                            'issued_at' => now(),
                            'issued_by' => $actor->id,
                        ]);
                    }
                }

                $activity->update([
                    'status' => FieldActivity::COMPLETED,
                    'completed_at' => $completedAt,
                    'completed_by' => $actor->id,
                    'area_covered' => $data['area_covered'] ?? $activity->area_covered,
                    'labour_hours' => $data['labour_hours'] ?? $activity->labour_hours,
                    'labour_cost' => $data['labour_cost'] ?? $activity->labour_cost,
                    'machine_hours' => $data['machine_hours'] ?? $activity->machine_hours,
                    'weather' => $data['weather'] ?? $activity->weather,
                    'notes' => $data['notes'] ?? $activity->notes,
                ]);

                if ($activity->machine_id && (float) ($data['machine_hours'] ?? 0) > 0) {
                    app(Machinery::class)->logUse($activity->machine, [
                        'used_on' => $completedAt->toDateString(), 'hours' => $data['machine_hours'],
                        'activity_id' => $activity->id, 'notes' => $activity->title,
                    ], $actor);
                }

                return $activity;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['inputs' => $e->getMessage()]);
        }
    }

    /**
     * Phase 2 §3.6 acceptance: the actual quantity of an input already issued was wrong. Nothing is
     * edited: the original issue is reversed in the stock ledger (its journal reversed with it) and
     * the right quantity issued as a new line that points at the one it corrects. Both stay in the
     * history and the audit trail; the cost reports, which read the books, show the net.
     */
    public function correctInput(ActivityInput $input, float $quantity, string $reason, User $actor): ?ActivityInput
    {
        if ($input->status !== ActivityInput::ISSUED || ! $input->stock_movement_id) {
            throw ValidationException::withMessages(['input' => 'Only an input that was issued can be corrected.']);
        }
        if ($quantity < 0 || abs($quantity - (float) $input->quantity) < 0.0001) {
            throw ValidationException::withMessages(['quantity' => 'Give the quantity actually used, different from what was recorded.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the quantity is being corrected.']);
        }
        $activity = $input->activity()->withoutGlobalScopes()->firstOrFail();
        $farm = $activity->farm;

        try {
            return DB::transaction(function () use ($input, $quantity, $reason, $actor, $activity, $farm) {
                // Every movement the line issued (one per lot when it came from several), each reversed.
                $original = FinStockMovement::withoutGlobalScopes()->findOrFail($input->stock_movement_id);
                $all = $original->idempotency_key
                    ? FinStockMovement::withoutGlobalScopes()->where('company_id', $original->company_id)
                        ->where(fn ($q) => $q->where('idempotency_key', $original->idempotency_key)->orWhere('idempotency_key', 'like', $original->idempotency_key.'#%'))->get()
                    : collect([$original]);
                foreach ($all as $i => $m) {
                    $this->stock->reverse($m, "Correction: {$reason}", "agri-input-reverse-{$input->uuid}".($i ? "#{$i}" : ''));
                }
                $input->update(['status' => ActivityInput::REVERSED, 'correction_reason' => mb_strimwidth($reason, 0, 300)]);
                if ($quantity <= 0) {
                    return null;   // none was used after all
                }
                $item = FinStockItem::withoutGlobalScopes()->findOrFail($input->stock_item_id);
                $enterprise = $activity->cropPlan ?? $activity->livestockGroup;
                $issued = $this->stores->issue($farm, $item, $quantity, [
                    'source_type' => 'agri_activity', 'source_id' => $activity->id, 'idempotency_key' => "agri-input-correct-{$input->uuid}",
                    'date' => today()->toDateString(), 'reference' => "{$farm->code} {$activity->title} (corrected)",
                    'expense_account' => $activity->livestock_group_id ? config('agri.accounts.animal_health') : config('agri.accounts.inputs'),
                    'reason_code' => 'AGRI_USE', 'actor' => $actor, 'dimensions' => AgriLedger::dimensions($farm, $activity->field, $enterprise, 'inputs'),
                ]);
                $movement = $issued['movement'];
                $total = $issued['value'];

                return ActivityInput::create(['company_id' => $input->company_id, 'farm_id' => $input->farm_id, 'activity_id' => $input->activity_id,
                    'stock_item_id' => $input->stock_item_id, 'warehouse_id' => $movement->warehouse_id, 'quantity' => $quantity, 'uom' => $input->uom,
                    'rate_per_ha' => $input->rate_per_ha, 'withholding_days' => $input->withholding_days, 'reentry_hours' => $input->reentry_hours,
                    'unit_cost' => round($total / $quantity, 4), 'total_cost' => $total, 'stock_movement_id' => $movement->id, 'status' => ActivityInput::ISSUED,
                    'issued_at' => now(), 'issued_by' => $actor->id, 'reversal_of_id' => $input->id, 'correction_reason' => mb_strimwidth($reason, 0, 300)]);
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    public function cancel(FieldActivity $activity): FieldActivity
    {
        $this->assertOpen($activity);
        $activity->update(['status' => FieldActivity::CANCELLED]);

        return $activity;
    }

    /**
     * The date before which a crop may not be harvested: the latest application plus its
     * withholding period, over every chemical issued to the plan.
     */
    public function withholdingUntil(CropPlan $plan): ?Carbon
    {
        $latest = null;
        $inputs = ActivityInput::query()
            ->where('status', ActivityInput::ISSUED)
            ->whereNotNull('withholding_days')
            ->whereHas('activity', fn ($q) => $q->where('crop_plan_id', $plan->id))
            ->with('activity')
            ->get();

        foreach ($inputs as $input) {
            $until = Carbon::parse($input->activity->completed_at)->startOfDay()->addDays((int) $input->withholding_days);
            if (! $latest || $until->gt($latest)) {
                $latest = $until;
            }
        }

        return $latest;
    }

    private function assertOpen(FieldActivity $activity): void
    {
        if (in_array($activity->status, [FieldActivity::COMPLETED, FieldActivity::CANCELLED], true)) {
            throw ValidationException::withMessages(['status' => "This activity is {$activity->status}."]);
        }
    }
}
