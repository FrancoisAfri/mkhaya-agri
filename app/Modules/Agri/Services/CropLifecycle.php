<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropStageEvent;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\SoilTest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §3.1: a field's whole story — its parts when it is split, the crops it carried and in
 * what order, its soil tests — and a crop's growth stages as they are seen.
 */
class CropLifecycle
{
    /** The usual stages; a farm may record its own (BBCH codes, "pod fill"…) as free text. */
    public const STAGES = ['pre_plant', 'emergence', 'vegetative', 'flowering', 'fruit_set', 'grain_fill', 'maturity', 'harvest_ready', 'harvested'];

    public function recordStage(CropPlan $plan, string $stage, string $on, ?string $notes, User $actor): CropStageEvent
    {
        if (! in_array($plan->status, [CropPlan::PLANTED, CropPlan::HARVESTING], true) && $stage !== 'pre_plant') {
            throw ValidationException::withMessages(['stage' => 'Record growth stages once the crop is planted.']);
        }
        $date = Carbon::parse($on);
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['observed_on' => 'A stage is recorded when it is seen, not ahead of time.']);
        }

        return DB::transaction(function () use ($plan, $stage, $date, $notes, $actor) {
            $event = CropStageEvent::create(['company_id' => $plan->company_id, 'farm_id' => $plan->farm_id, 'crop_plan_id' => $plan->id,
                'stage' => mb_strimwidth(trim($stage), 0, 40), 'observed_on' => $date, 'notes' => $notes, 'recorded_by' => $actor->id]);
            // The plan shows its latest stage; an older stage entered late does not move it back.
            $latest = CropStageEvent::query()->where('crop_plan_id', $plan->id)->orderByDesc('observed_on')->orderByDesc('id')->first();
            $plan->update(['current_stage' => $latest->stage]);

            return $event;
        });
    }

    /**
     * What a field carried, newest first, including its parts when it was split, and the warnings a
     * planned crop raises: the same crop, or the same kind of crop, straight after itself.
     *
     * @return array{history: list<array<string, mixed>>, warnings: list<string>}
     */
    public function rotation(FarmField $field, ?int $plannedCropTypeId = null, ?string $before = null): array
    {
        $ids = FarmField::withoutGlobalScopes()->where('parent_field_id', $field->id)->pluck('id')->push($field->id)
            ->when($field->parent_field_id, fn ($c) => $c->push($field->parent_field_id));
        $plans = CropPlan::query()->whereIn('field_id', $ids)->whereNotIn('status', [CropPlan::CANCELLED])
            ->when($before, fn ($q) => $q->whereDate('planned_planting_on', '<', $before))
            ->with(['cropType', 'variety', 'season'])->orderByDesc('planned_planting_on')->get();
        $history = $plans->map(fn (CropPlan $p) => ['code' => $p->code, 'season' => $p->season?->name, 'crop' => $p->cropType?->name,
            'category' => $p->cropType?->category, 'variety' => $p->variety?->name, 'planted' => ($p->planted_on ?? $p->planned_planting_on)?->toDateString(),
            'status' => $p->status, 'intercrop' => (bool) $p->intercrop])->values()->all();

        $warnings = [];
        if ($plannedCropTypeId && ($type = CropType::query()->find($plannedCropTypeId))) {
            $last = $plans->first(fn ($p) => ! $p->intercrop);
            if ($last && (int) $last->crop_type_id === (int) $type->id) {
                $warnings[] = "{$type->name} follows {$type->name} on {$field->code} ({$last->code}). Disease and pest carry-over risk; check the rotation.";
            } elseif ($last && $type->category && $last->cropType?->category === $type->category) {
                $warnings[] = "Two {$type->category} crops in a row on {$field->code} ({$last->code}, {$last->cropType?->name}).";
            }
            $recent = $plans->filter(fn ($p) => (int) $p->crop_type_id === (int) $type->id && ($p->planted_on ?? $p->planned_planting_on)?->gte(today()->subYears(3)))->count();
            if ($recent >= 2) {
                $warnings[] = "{$type->name} has been on {$field->code} {$recent} times in three years.";
            }
        }

        return ['history' => $history, 'warnings' => $warnings];
    }

    /**
     * Split a field into parts that are planned separately. The parts must fit inside it; the field
     * keeps its history and becomes their whole.
     *
     * @param  list<array{code: string, name: string, area: float}>  $parts
     * @return list<FarmField>
     */
    public function split(FarmField $field, array $parts, User $actor): array
    {
        if ($field->parent_field_id) {
            throw ValidationException::withMessages(['field' => 'A part cannot be split again; split the whole field differently instead.']);
        }
        if (count($parts) < 2) {
            throw ValidationException::withMessages(['parts' => 'A split needs at least two parts.']);
        }
        $total = array_sum(array_map(fn ($p) => (float) $p['area'], $parts));
        $whole = $field->hectares();
        if ($whole !== null && $total > $whole + 0.0001) {
            throw ValidationException::withMessages(['parts' => sprintf('The parts add up to %s ha; %s is %s ha.', round($total, 2), $field->code, round($whole, 2))]);
        }
        $live = CropPlan::query()->where('field_id', $field->id)->whereIn('status', [CropPlan::DRAFT, CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING])->pluck('code');
        if ($live->isNotEmpty()) {
            throw ValidationException::withMessages(['field' => "{$field->code} has live plans ({$live->implode(', ')}); finish or move them first."]);
        }

        return DB::transaction(fn () => array_map(fn ($p) => FarmField::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'parent_field_id' => $field->id, 'code' => $p['code'], 'name' => $p['name'],
            'land_use_type' => $field->land_use_type, 'area' => $p['area'], 'area_unit' => 'ha', 'soil_type' => $field->soil_type,
            'irrigation' => $field->irrigation, 'water_source' => $field->water_source, 'slope_pct' => $field->slope_pct,
            'latitude' => $field->latitude, 'longitude' => $field->longitude, 'status' => $field->status, 'created_by' => $actor->id]), $parts));
    }

    public function recordSoilTest(FarmField $field, array $data, User $actor): SoilTest
    {
        if (Carbon::parse($data['sampled_on'])->isAfter(today())) {
            throw ValidationException::withMessages(['sampled_on' => 'A sample is recorded after it was taken.']);
        }

        return SoilTest::create(array_intersect_key($data, array_flip(['sampled_on', 'laboratory', 'sample_ref', 'depth_cm', 'latitude', 'longitude', 'ph',
            'organic_carbon_pct', 'n_mg_kg', 'p_mg_kg', 'k_mg_kg', 'ca_mg_kg', 'mg_mg_kg', 'ec_ms_m', 'other_results', 'recommendation',
            'report_name', 'report_mime', 'report_data'])) + ['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
                'recorded_by' => $actor->id]);
    }
}
