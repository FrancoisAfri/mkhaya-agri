<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The rules of a crop plan (spec Module B), in one place for the screens, imports and the API.
 *
 *  - A plan is for one field of its farm, in an open season, with planting before harvest.
 *  - The plans holding a field at the same time cannot add up to more than the field's area.
 *  - A plan moves only along its lifecycle; a drafter does not approve their own plan (unless they
 *    are the owner, who answers to nobody), and nothing is planted before it is approved.
 *  - A plan with harvests on it is history: it can be completed, never cancelled.
 */
class CropPlanService
{
    private const TRANSITIONS = [
        CropPlan::DRAFT => [CropPlan::APPROVED, CropPlan::CANCELLED],
        CropPlan::APPROVED => [CropPlan::PLANTED, CropPlan::CANCELLED, CropPlan::DRAFT],
        CropPlan::PLANTED => [CropPlan::HARVESTING, CropPlan::COMPLETED, CropPlan::CANCELLED],
        CropPlan::HARVESTING => [CropPlan::COMPLETED],
        CropPlan::COMPLETED => [],
        CropPlan::CANCELLED => [],
    ];

    public function __construct(private readonly FarmAccess $access, private readonly FarmRegistry $registry) {}

    public function create(Farm $farm, FarmField $field, array $data, User $actor): CropPlan
    {
        $this->registry->assertOperational($farm);
        $this->assertCodeFree($farm, $data['code']);
        $this->validatePlan($farm, $field, $data);

        return CropPlan::create($data + [
            'company_id' => $farm->company_id,
            'farm_id' => $farm->id,
            'field_id' => $field->id,
            'status' => CropPlan::DRAFT,
            'created_by' => $actor->id,
        ]);
    }

    public function update(CropPlan $plan, FarmField $field, array $data): CropPlan
    {
        if (! in_array($plan->status, [CropPlan::DRAFT, CropPlan::APPROVED], true)) {
            throw ValidationException::withMessages(['status' => "A {$plan->status} plan cannot be re-planned. Record what happened on it instead."]);
        }
        if (isset($data['code'])) {
            $this->assertCodeFree($plan->farm, $data['code'], $plan->id);
        }
        $this->validatePlan($plan->farm, $field, $data, $plan->id);

        // Changing an approved plan sends it back for approval.
        $plan->update($data + ['field_id' => $field->id] + ($plan->status === CropPlan::APPROVED
            ? ['status' => CropPlan::DRAFT, 'approved_by' => null, 'approved_at' => null] : []));

        return $plan;
    }

    public function approve(CropPlan $plan, User $actor): CropPlan
    {
        $this->assertTransition($plan, CropPlan::APPROVED);
        if ((int) $plan->created_by === (int) $actor->id && ! $this->access->isUnrestricted($actor)) {
            throw ValidationException::withMessages(['status' => 'You drafted this plan, so somebody else must approve it.']);
        }
        $plan->update(['status' => CropPlan::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $plan;
    }

    public function plant(CropPlan $plan, string $date): CropPlan
    {
        $this->assertTransition($plan, CropPlan::PLANTED);
        if (Carbon::parse($date)->isAfter(today())) {
            throw ValidationException::withMessages(['planted_on' => 'Planting cannot be recorded for a date that has not happened yet.']);
        }
        $plan->update(['status' => CropPlan::PLANTED, 'planted_on' => $date]);

        return $plan;
    }

    /** First harvest on a planted plan moves it to harvesting. */
    public function startHarvest(CropPlan $plan): void
    {
        if ($plan->status === CropPlan::PLANTED) {
            $plan->update(['status' => CropPlan::HARVESTING]);
        }
    }

    public function complete(CropPlan $plan, string $date): CropPlan
    {
        $this->assertTransition($plan, CropPlan::COMPLETED);
        if (! $plan->harvests()->exists()) {
            throw ValidationException::withMessages(['status' => 'Record at least one harvest (or a loss) before completing the plan.']);
        }
        $plan->update(['status' => CropPlan::COMPLETED, 'harvest_completed_on' => $date]);

        return $plan;
    }

    public function cancel(CropPlan $plan, string $reason): CropPlan
    {
        $this->assertTransition($plan, CropPlan::CANCELLED);
        if ($plan->harvests()->exists()) {
            throw ValidationException::withMessages(['status' => 'This plan has harvests recorded, so it cannot be cancelled. Complete it instead.']);
        }
        $plan->update(['status' => CropPlan::CANCELLED, 'cancel_reason' => $reason]);

        return $plan;
    }

    public function canMove(CropPlan $plan, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$plan->status] ?? [], true);
    }

    private function assertTransition(CropPlan $plan, string $to): void
    {
        if (! $this->canMove($plan, $to)) {
            throw ValidationException::withMessages(['status' => "A {$plan->status} plan cannot become {$to}."]);
        }
    }

    private function validatePlan(Farm $farm, FarmField $field, array $data, ?int $exceptId = null): void
    {
        if ((int) $field->farm_id !== (int) $farm->id) {
            throw ValidationException::withMessages(['field_id' => 'That field is not on this farm.']);
        }

        $season = CropSeason::query()->find($data['season_id']);
        if (! $season) {
            throw ValidationException::withMessages(['season_id' => 'Choose a season.']);
        }
        if ($season->status !== CropSeason::OPEN) {
            throw ValidationException::withMessages(['season_id' => "Season {$season->name} is closed."]);
        }

        if (! empty($data['variety_id'])) {
            $ok = CropVariety::query()->whereKey($data['variety_id'])->where('crop_type_id', $data['crop_type_id'])->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['variety_id' => 'That variety is not of the chosen crop.']);
            }
        }

        $plant = Carbon::parse($data['planned_planting_on']);
        $harvest = Carbon::parse($data['planned_harvest_on']);
        if (! $harvest->isAfter($plant)) {
            throw ValidationException::withMessages(['planned_harvest_on' => 'Harvest must be planned after planting.']);
        }
        if ($plant->lt($season->starts_on) || $plant->gt($season->ends_on)) {
            throw ValidationException::withMessages(['planned_planting_on' => "Planting must fall inside season {$season->name} ({$season->starts_on->toDateString()} to {$season->ends_on->toDateString()})."]);
        }

        $hectares = Farm::hectares((float) $data['planned_area'], $data['area_unit'] ?? 'ha');
        $fieldHa = $field->hectares();
        if ($fieldHa !== null) {
            // Plans that hold this field over any part of the same period.
            $committed = CropPlan::query()
                ->where('field_id', $field->id)
                ->whereIn('status', CropPlan::LIVE)
                ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
                ->whereDate('planned_planting_on', '<', $harvest)
                ->whereDate('planned_harvest_on', '>', $plant)
                ->get()
                ->sum(fn (CropPlan $p) => $p->hectares());

            if ($committed + $hectares > $fieldHa + 0.0001) {
                throw ValidationException::withMessages(['planned_area' => sprintf(
                    'Field %s is %s ha and %s ha of it is already planned for that period, so %s ha does not fit.',
                    $field->code, round($fieldHa, 2), round($committed, 2), round($hectares, 2),
                )]);
            }
        }
    }

    private function assertCodeFree(Farm $farm, string $code, ?int $exceptId = null): void
    {
        $taken = CropPlan::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)
            ->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "This farm already has a crop plan {$code}."]);
        }
    }
}
