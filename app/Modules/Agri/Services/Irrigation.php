<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\IrrigationException;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\SoilMoistureReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §3.4: irrigation planned and given, by field, zone and crop — duration, water, pump hours,
 * energy and what they cost — soil moisture, exceptions (leaks, faults, too much or too little), and
 * water-use efficiency against what was harvested.
 *
 * This records and advises; it does not switch pumps or valves. Automatic control would need explicit
 * permissions, safe limits and a manual override, and is not part of this release.
 */
class Irrigation
{
    /** Given water this far from the plan is an exception worth a look. */
    public const TOLERANCE = 0.25;

    public function __construct(private readonly Machinery $machinery) {}

    public function plan(FarmField $field, array $data, User $actor): IrrigationEvent
    {
        $zone = $this->zone($field, $data['zone_id'] ?? null);
        $minutes = (int) ($data['planned_minutes'] ?? 0);
        $m3 = $data['planned_m3'] ?? ($zone?->flow_m3_per_hour && $minutes ? round($zone->flow_m3_per_hour * $minutes / 60, 3) : null);
        if (! $minutes && ! $m3) {
            throw ValidationException::withMessages(['planned_minutes' => 'Give a duration or a volume.']);
        }

        return IrrigationEvent::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'zone_id' => $zone?->id,
            'crop_plan_id' => $this->planOn($field, Carbon::parse($data['planned_start'] ?? now()))?->id, 'planned_start' => $data['planned_start'] ?? now(),
            'planned_minutes' => $minutes ?: null, 'planned_m3' => $m3, 'status' => 'planned', 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id]);
    }

    /**
     * What was actually given — typed in, or from a meter. Volume from the meter or the zone's flow
     * rate; pump hours go on the pump; water and energy are costed at the zone's rates; a large
     * difference from the plan is raised as an exception.
     */
    public function record(IrrigationEvent $event, array $data, User $actor): IrrigationEvent
    {
        if ($event->status === 'done' && empty($data['correction'])) {
            throw ValidationException::withMessages(['status' => 'Already recorded.']);
        }
        if (! empty($data['client_id']) && ($seen = IrrigationEvent::withoutGlobalScopes()->where('client_id', $data['client_id'])->where('id', '!=', $event->id)->first())) {
            return $seen;
        }
        $zone = $event->zone;
        $minutes = (int) ($data['actual_minutes'] ?? $event->planned_minutes ?? 0);
        $m3 = isset($data['actual_m3']) ? (float) $data['actual_m3'] : ($zone?->flow_m3_per_hour && $minutes ? round($zone->flow_m3_per_hour * $minutes / 60, 3) : null);
        if ($m3 === null) {
            throw ValidationException::withMessages(['actual_m3' => 'Give the volume, or set the zone\'s flow rate so it can be worked out.']);
        }
        $hours = round($minutes / 60, 2);
        $kwh = $zone?->energy_kwh_per_hour ? round($zone->energy_kwh_per_hour * $hours, 2) : ($data['energy_kwh'] ?? null);

        return DB::transaction(function () use ($event, $data, $actor, $zone, $minutes, $m3, $hours, $kwh) {
            $event->update(['actual_start' => $data['actual_start'] ?? $event->planned_start ?? now(), 'actual_minutes' => $minutes ?: null, 'actual_m3' => $m3,
                'source' => $data['source'] ?? 'manual', 'pump_hours' => $hours ?: null, 'energy_kwh' => $kwh,
                'water_cost' => $zone?->water_cost_per_m3 !== null ? round($m3 * $zone->water_cost_per_m3, 2) : null,
                'energy_cost' => $kwh !== null && $zone?->energy_cost_per_kwh !== null ? round($kwh * $zone->energy_cost_per_kwh, 2) : null,
                'status' => 'done', 'client_id' => $data['client_id'] ?? $event->client_id, 'notes' => $data['notes'] ?? $event->notes]);
            if ($hours > 0 && $zone?->pump_machine_id && ($pump = FarmMachine::query()->find($zone->pump_machine_id)) && $pump->status !== FarmMachine::DISPOSED) {
                $this->machinery->logUse($pump, ['used_on' => Carbon::parse($event->actual_start)->toDateString(), 'hours' => $hours, 'notes' => 'Irrigation '.($zone->code ?? '')], $actor);
            }
            if ($event->planned_m3 && abs($m3 - $event->planned_m3) / $event->planned_m3 > self::TOLERANCE) {
                $this->exception($event->field_id, $event->zone_id, $m3 > $event->planned_m3 ? 'over_applied' : 'under_applied',
                    sprintf('%s m³ given against %s m³ planned.', round($m3, 1), round($event->planned_m3, 1)), $actor, $event->id);
            }

            return $event->fresh();
        });
    }

    public function skip(IrrigationEvent $event, string $reason): IrrigationEvent
    {
        $event->update(['status' => 'skipped', 'notes' => trim(($event->notes ? $event->notes.' · ' : '').$reason)]);

        return $event;
    }

    public function moisture(FarmField $field, array $data, ?User $actor): SoilMoistureReading
    {
        $vwc = (float) $data['vwc_pct'];
        if ($vwc < 0 || $vwc > 100) {
            throw ValidationException::withMessages(['vwc_pct' => 'Soil moisture is a percentage.']);
        }

        return SoilMoistureReading::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
            'zone_id' => $data['zone_id'] ?? null, 'read_at' => $data['read_at'] ?? now(), 'depth_cm' => $data['depth_cm'] ?? null, 'vwc_pct' => $vwc,
            'source' => $data['source'] ?? 'manual', 'device_ref' => $data['device_ref'] ?? null, 'recorded_by' => $actor?->id]);
    }

    public function exception(?int $fieldId, ?int $zoneId, string $kind, ?string $note, ?User $actor, ?int $eventId = null): IrrigationException
    {
        if (! in_array($kind, IrrigationException::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown kind of irrigation problem.']);
        }
        $field = $fieldId ? FarmField::query()->find($fieldId) : null;
        $zone = $zoneId ? IrrigationZone::query()->find($zoneId) : null;

        return IrrigationException::create(['company_id' => ($field ?? $zone)->company_id, 'farm_id' => ($field ?? $zone)->farm_id,
            'field_id' => $fieldId ?? $zone?->field_id, 'zone_id' => $zoneId, 'event_id' => $eventId, 'kind' => $kind, 'note' => $note, 'recorded_by' => $actor?->id]);
    }

    public function resolve(IrrigationException $x, string $resolution, User $actor): IrrigationException
    {
        $x->update(['resolved_at' => now(), 'resolved_by' => $actor->id, 'resolution' => mb_strimwidth($resolution, 0, 500)]);

        return $x;
    }

    /**
     * Water given to a crop (by its plan, or its field over the plan's season), what it cost, and how
     * much was harvested per cubic metre.
     */
    public function forPlan(CropPlan $plan): array
    {
        $events = IrrigationEvent::query()->where('status', 'done')->where(fn ($q) => $q->where('crop_plan_id', $plan->id)
            ->orWhere(fn ($q) => $q->whereNull('crop_plan_id')->where('field_id', $plan->field_id)
                ->whereBetween('actual_start', [($plan->planted_on ?? $plan->planned_planting_on)->copy()->startOfDay(),
                    ($plan->harvest_completed_on ?? $plan->planned_harvest_on)->copy()->endOfDay()])))->get();
        $m3 = (float) $events->sum('actual_m3');
        $ha = $plan->hectares();
        $harvested = (float) HarvestRecord::query()->where('crop_plan_id', $plan->id)->where('destination', '!=', 'loss')->sum('quantity');

        return [
            'events' => $events->count(), 'm3' => round($m3, 1), 'mm' => $ha > 0 ? round($m3 / $ha / 10, 1) : null,
            'pump_hours' => round((float) $events->sum('pump_hours'), 1), 'energy_kwh' => round((float) $events->sum('energy_kwh'), 1),
            'water_cost' => round((float) $events->sum('water_cost'), 2), 'energy_cost' => round((float) $events->sum('energy_cost'), 2),
            'harvested' => round($harvested, 3), 'unit' => $plan->yield_unit,
            'per_m3' => $m3 > 0 && $harvested > 0 ? round($harvested / $m3, 4) : null,          // water-use efficiency
            'm3_per_unit' => $harvested > 0 ? round($m3 / $harvested, 3) : null,
        ];
    }

    private function zone(FarmField $field, $id): ?IrrigationZone
    {
        if (! $id) {
            return null;
        }
        $zone = IrrigationZone::query()->where(is_numeric($id) ? 'id' : 'uuid', $id)->first();
        if (! $zone || (int) $zone->field_id !== (int) $field->id) {
            throw ValidationException::withMessages(['zone_id' => 'That zone is not on this field.']);
        }

        return $zone;
    }

    /** The crop in the ground on the field at that moment, if any. */
    private function planOn(FarmField $field, Carbon $at): ?CropPlan
    {
        return CropPlan::query()->where('field_id', $field->id)->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING])
            ->where('intercrop', false)->whereDate('planned_planting_on', '<=', $at)->whereDate('planned_harvest_on', '>=', $at->copy()->subDays(30))
            ->orderByDesc('planned_planting_on')->first();
    }
}
