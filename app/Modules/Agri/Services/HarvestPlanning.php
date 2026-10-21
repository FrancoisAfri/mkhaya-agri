<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmWorkOrder;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §3.5: when a crop will be ready and how much it should give — always labelled an
 * estimate, with what it was based on — what it will take to bring in (containers, loads, the
 * harvest jobs), who it is promised to, and what actually came in against all of that.
 *
 * Measured figures come only from harvest records. A forecast never overwrites one, and the screens
 * show the two side by side, never mixed.
 */
class HarvestPlanning
{
    /** How much each open problem trims the estimate, by severity. Simple, stated, and shown with the forecast. */
    private const PENALTY = ['medium' => 0.03, 'high' => 0.08, 'critical' => 0.15];

    private const MAX_PENALTY = 0.35;

    /**
     * The model forecast: yield per hectare from this field's (else this farm's) measured history for
     * the crop, else the plan's own expectation; trimmed for open pest and disease problems; ready on
     * planting + the variety's days to maturity, else the planned harvest date.
     */
    public function forecast(CropPlan $plan, ?User $actor = null): HarvestForecast
    {
        $plan->loadMissing(['variety', 'cropType']);
        $ha = $plan->hectares();
        $basis = [];

        [$perHa, $source, $seasons] = $this->historicalYield($plan);
        if ($perHa === null) {
            if ($plan->expected_yield === null) {
                throw ValidationException::withMessages(['forecast' => 'No harvest history for this crop here and no expected yield on the plan: nothing to forecast from.']);
            }
            $perHa = (float) $plan->expected_yield;
            $source = "the plan's expected yield";
        }
        $basis[] = sprintf('%s %s/ha from %s', round($perHa, 2), $plan->yield_unit, $source.($seasons ? " ({$seasons} season(s))" : ''));

        $problems = CropObservation::query()->where('crop_plan_id', $plan->id)->whereIn('kind', ['pest', 'disease', 'weather_damage', 'crop_damage', 'water_stress'])
            ->where('status', '!=', 'closed')->get();
        $penalty = min(self::MAX_PENALTY, $problems->sum(fn ($o) => self::PENALTY[$o->severity] ?? 0));
        if ($penalty > 0) {
            $basis[] = sprintf('less %d%% for %d open crop problem(s)', round($penalty * 100), $problems->count());
        }

        $qty = $perHa * $ha * (1 - $penalty);
        $spread = $seasons >= 3 ? 0.10 : ($seasons ? 0.15 : 0.25);   // less history, wider range
        $basis[] = sprintf('range ±%d%%', $spread * 100);

        $planted = $plan->planted_on ?? $plan->planned_planting_on;
        $ready = $plan->variety?->days_to_maturity && $planted ? $planted->copy()->addDays((int) $plan->variety->days_to_maturity) : $plan->planned_harvest_on;
        $basis[] = $plan->variety?->days_to_maturity ? "ready {$plan->variety->days_to_maturity} days after planting" : 'ready on the planned harvest date';
        $basis[] = 'Weather is not considered yet (no weather source connected).';

        return HarvestForecast::create(['company_id' => $plan->company_id, 'farm_id' => $plan->farm_id, 'crop_plan_id' => $plan->id, 'made_on' => today(),
            'ready_on' => $ready, 'quantity' => round($qty, 3), 'low' => round($qty * (1 - $spread), 3), 'high' => round($qty * (1 + $spread), 3),
            'unit' => $plan->yield_unit, 'method' => 'model', 'basis' => $basis, 'made_by' => $actor?->id]);
    }

    /** A person's own estimate (a crop walk, a yield sample) — kept beside the model's, labelled as theirs. */
    public function manualForecast(CropPlan $plan, float $quantity, ?string $readyOn, string $how, User $actor): HarvestForecast
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'A forecast is more than zero.']);
        }

        return HarvestForecast::create(['company_id' => $plan->company_id, 'farm_id' => $plan->farm_id, 'crop_plan_id' => $plan->id, 'made_on' => today(),
            'ready_on' => $readyOn, 'quantity' => $quantity, 'unit' => $plan->yield_unit, 'method' => 'manual', 'basis' => [mb_strimwidth($how, 0, 300)],
            'made_by' => $actor->id]);
    }

    /**
     * The plan's harvest at a glance: latest estimate, what came in, losses, containers and loads,
     * the harvest jobs, and what is promised to buyers.
     */
    public function overview(CropPlan $plan): array
    {
        $plan->loadMissing('cropType');
        $f = HarvestForecast::query()->where('crop_plan_id', $plan->id)->latest('id')->first();
        $records = HarvestRecord::query()->where('crop_plan_id', $plan->id)->orderBy('harvested_on')->get();
        $harvested = (float) $records->where('destination', '!=', 'loss')->sum('quantity');
        $remaining = $f ? max(0, $f->quantity - $harvested) : null;
        $type = $plan->cropType;
        $commitments = HarvestCommitment::query()->where(fn ($q) => $q->where('crop_plan_id', $plan->id)
            ->orWhere(fn ($q) => $q->whereNull('crop_plan_id')->where('crop_type_id', $plan->crop_type_id)->where('farm_id', $plan->farm_id)))
            ->whereIn('status', ['open', 'short', 'delivered'])->orderBy('deliver_on')->get();

        return [
            'forecast' => $f ? ['quantity' => $f->quantity, 'low' => $f->low, 'high' => $f->high, 'ready_on' => $f->ready_on?->toDateString(),
                'method' => $f->method, 'basis' => $f->basis, 'made_on' => $f->made_on->toDateString(), 'estimate' => true] : null,
            'measured' => ['harvested' => round($harvested, 3), 'rejected' => round((float) $records->sum('rejected_quantity'), 3),
                'spoiled' => round((float) $records->sum('spoiled_quantity'), 3),
                'field_loss' => round((float) $records->sum('field_loss_quantity') + (float) $records->where('destination', 'loss')->sum('quantity'), 3),
                'saleable' => round($harvested - (float) $records->sum('rejected_quantity') - (float) $records->sum('spoiled_quantity'), 3),
                'by_grade' => $records->where('destination', '!=', 'loss')->groupBy(fn ($r) => $r->grade ?: 'ungraded')->map(fn ($g) => round((float) $g->sum('quantity'), 3))->all(),
                'lots' => $records->map(fn ($r) => ['lot' => $r->lot_code, 'on' => $r->harvested_on?->toDateString(), 'quantity' => (float) $r->quantity,
                    'grade' => $r->grade, 'destination' => $r->destination, 'store_movement' => $r->stock_movement_id])->values()->all()],
            'logistics' => $remaining !== null ? [
                'remaining' => round($remaining, 3),
                'container' => $type?->container_name, 'containers' => $type?->container_capacity ? (int) ceil($remaining / $type->container_capacity) : null,
                'loads' => $type?->load_capacity ? (int) ceil($remaining / $type->load_capacity) : null,
            ] : null,
            'jobs' => FarmWorkOrder::query()->where('crop_plan_id', $plan->id)->where('category', 'harvesting')->orderBy('planned_start')->get()
                ->map(fn ($w) => ['id' => $w->uuid, 'reference' => $w->reference, 'title' => $w->title, 'status' => $w->status, 'starts' => $w->planned_start->format('Y-m-d H:i')])->all(),
            'commitments' => $commitments->map(fn ($c) => $this->commitmentRow($c))->all(),
            'committed' => round((float) $commitments->where('status', '!=', 'cancelled')->sum(fn ($c) => $c->quantity - $c->delivered_quantity), 3),
        ];
    }

    public function commit(CropPlan|null $plan, int $cropTypeId, int $farmId, int $companyId, array $data, User $actor): HarvestCommitment
    {
        if (Carbon::parse($data['deliver_on'])->lt(today()->subDays(1))) {
            throw ValidationException::withMessages(['deliver_on' => 'A commitment is for a delivery still to come.']);
        }

        return HarvestCommitment::create(['company_id' => $companyId, 'farm_id' => $farmId, 'crop_plan_id' => $plan?->id, 'crop_type_id' => $cropTypeId,
            'buyer' => $data['buyer'], 'party_id' => $data['party_id'] ?? null, 'channel' => $data['channel'] ?? null, 'quantity' => $data['quantity'],
            'unit' => $data['unit'], 'grade' => $data['grade'] ?? null, 'deliver_on' => $data['deliver_on'], 'price_per_unit' => $data['price_per_unit'] ?? null,
            'status' => 'open', 'created_by' => $actor->id]);
    }

    /** A harvest delivered against a commitment: counted once, and the commitment closes when it is met. */
    public function deliver(HarvestCommitment $c, HarvestRecord $record): HarvestCommitment
    {
        if ($record->commitment_id && (int) $record->commitment_id !== (int) $c->id) {
            throw ValidationException::withMessages(['commitment_id' => 'That harvest already went to another commitment.']);
        }
        if (in_array($c->status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['commitment_id' => "That commitment is {$c->status}."]);
        }
        $record->update(['commitment_id' => $c->id]);
        $delivered = (float) HarvestRecord::query()->where('commitment_id', $c->id)->sum('quantity');
        $c->update(['delivered_quantity' => $delivered, 'status' => $delivered + 0.0005 >= $c->quantity ? 'delivered' : 'open']);

        return $c;
    }

    /** Commitments past their date and not met become "short", for the alerts and the buyer conversation. */
    public function markShort(int $companyId): int
    {
        return HarvestCommitment::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'open')
            ->whereDate('deliver_on', '<', today())->update(['status' => 'short', 'updated_at' => now()]);
    }

    public function commitmentRow(HarvestCommitment $c): array
    {
        return ['id' => $c->uuid, 'buyer' => $c->buyer, 'channel' => $c->channel, 'quantity' => $c->quantity, 'delivered' => $c->delivered_quantity,
            'unit' => $c->unit, 'grade' => $c->grade, 'deliver_on' => $c->deliver_on->toDateString(), 'price' => $c->price_per_unit, 'status' => $c->status];
    }

    /** @return array{0: ?float, 1: ?string, 2: int} yield per ha, where it came from, how many seasons */
    private function historicalYield(CropPlan $plan): array
    {
        foreach ([['field_id', $plan->field_id, 'this field'], ['farm_id', $plan->farm_id, 'this farm']] as [$col, $val, $label]) {
            $past = CropPlan::query()->where($col, $val)->where('crop_type_id', $plan->crop_type_id)->where('status', CropPlan::COMPLETED)
                ->where('id', '!=', $plan->id)->get();
            $rates = $past->map(function ($p) {
                $ha = $p->hectares();
                $q = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');

                return $ha > 0 && $q > 0 ? $q / $ha : null;
            })->filter();
            if ($rates->isNotEmpty()) {
                return [$rates->avg(), "measured harvests on {$label}", $rates->count()];
            }
        }

        return [null, null, 0];
    }
}
