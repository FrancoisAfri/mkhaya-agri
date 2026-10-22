<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\ImageryFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §16B: imagery as a pointer, never a verdict.
 *
 * A capture's vegetation index (from a satellite service or a drone survey) is kept per field with
 * its source and date. Set against the field's previous capture of the same index, a drop larger
 * than the threshold — across the field, or in any of its zones — becomes a flag: a scouting
 * observation for someone to go and look, linked if need be to a work order. Each flag is closed
 * as confirmed or a false alarm, and track() reports how often the imagery was right, so the farm
 * knows how far to trust it. A cloudy capture is kept but never flags.
 */
class CropImagery
{
    public const DROP_PCT = 15.0;     // a fall of this much in the mean, or in a zone, is worth a look
    public const MAX_CLOUD = 30.0;

    /**
     * $actor is null for the scheduled satellite fetch. $data['flag'] = false keeps a capture
     * without comparing it for a drop (a secondary index fetched beside NDVI).
     */
    public function import(FarmField $field, array $data, ?User $actor): array
    {
        if (! in_array($data['index'], FieldImagery::INDICES, true)) {
            throw ValidationException::withMessages(['index' => 'Choose the vegetation index.']);
        }

        return DB::transaction(function () use ($field, $data, $actor) {
            $prev = FieldImagery::query()->where('field_id', $field->id)->where('index', $data['index'])->where('captured_on', '<', $data['captured_on'])
                ->where(fn ($q) => $q->whereNull('cloud_pct')->orWhere('cloud_pct', '<=', self::MAX_CLOUD))->orderByDesc('captured_on')->first();
            $plan = CropPlan::query()->where('field_id', $field->id)->whereIn('status', CropPlan::LIVE)->first();
            $change = $prev && $prev->mean != 0 ? round(((float) $data['mean'] - $prev->mean) / abs($prev->mean) * 100, 2) : null;
            $img = FieldImagery::query()->updateOrCreate(['field_id' => $field->id, 'index' => $data['index'], 'captured_on' => $data['captured_on'], 'source' => $data['source']],
                ['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'crop_plan_id' => $plan?->id, 'mean' => $data['mean'], 'min' => $data['min'] ?? null,
                    'max' => $data['max'] ?? null, 'cloud_pct' => $data['cloud_pct'] ?? null, 'cells' => $data['cells'] ?? null, 'image_mime' => $data['image_mime'] ?? null,
                    'image_data' => $data['image_data'] ?? null, 'change_pct' => $change, 'recorded_by' => $actor?->id]);
            $flag = null;
            $suppressed = null;
            if ($prev && ($data['flag'] ?? true) && (float) ($data['cloud_pct'] ?? 0) <= self::MAX_CLOUD) {
                $prevCells = collect($prev->cells ?? [])->keyBy('id');
                $dropped = collect($data['cells'] ?? [])->filter(function ($c) use ($prevCells) {
                    $before = (float) ($prevCells[$c['id']]['value'] ?? 0);

                    return $before > 0 && ((float) $c['value'] - $before) / $before * 100 <= -self::DROP_PCT;
                })->values();
                $drop = ($change !== null && $change <= -self::DROP_PCT) || $dropped->isNotEmpty();
                // A cut, mowing or harvest just before the capture explains the drop: no flag, but say why.
                if ($drop && ($suppressed = $this->expectedDrop($field, $plan, \Illuminate\Support\Carbon::parse($data['captured_on'])))) {
                    $drop = false;
                }
                if ($drop) {
                    $why = $change !== null && $change <= -self::DROP_PCT
                        ? strtoupper($data['index'])." fell {$change}% across {$field->code} since {$prev->captured_on->toDateString()}"
                        : $dropped->count().' zone(s) of '.$field->code.' fell more than '.self::DROP_PCT.'% since '.$prev->captured_on->toDateString();
                    $first = $dropped->first();
                    $obs = CropObservation::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'crop_plan_id' => $plan?->id,
                        'observed_on' => today(), 'kind' => 'plant_health', 'severity' => 'medium', 'status' => 'open', 'action_required' => true,
                        'description' => "Imagery ({$data['source']}): {$why}. Go and look — imagery shows where, not what.", 'latitude' => $first['lat'] ?? null, 'longitude' => $first['lng'] ?? null,
                        'recorded_by' => $actor?->id]);
                    $flag = ImageryFlag::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'imagery_id' => $img->id, 'field_id' => $field->id,
                        'reason' => mb_strimwidth($why, 0, 300), 'cells' => $dropped->all() ?: null, 'observation_id' => $obs->id]);
                }
            }

            if ($img->flag_suppressed !== $suppressed) {
                $img->forceFill(['flag_suppressed' => $suppressed])->save();
            }

            return ['imagery' => $img, 'flag' => $flag, 'change_pct' => $change, 'suppressed' => $suppressed];
        });
    }

    /**
     * Built-in "expected drop" rules, used while an organisation has written none of its own: fodder
     * (lucerne, oats, teff) and pasture after a cut or mowing, and any crop after it is harvested.
     *
     * @return list<array{crop_type_id: ?int, category: ?string, activity_types: list<string>, after_harvest: bool, within_days: int, note: string}>
     */
    public static function defaultDropRules(): array
    {
        return [
            ['crop_type_id' => null, 'category' => 'fodder', 'activity_types' => ['mowing', 'harvesting'], 'after_harvest' => true, 'within_days' => 28,
                'note' => 'Fodder and pasture: NDVI falls after every cut and recovers as it regrows.'],
            ['crop_type_id' => null, 'category' => null, 'activity_types' => ['harvesting'], 'after_harvest' => true, 'within_days' => 30,
                'note' => 'Any crop: the canopy is gone once it is harvested.'],
        ];
    }

    /** The reason a drop on this capture is expected, or null when it is not. */
    public function expectedDrop(FarmField $field, ?CropPlan $plan, \Illuminate\Support\Carbon $capturedOn): ?string
    {
        $plan ??= CropPlan::withoutGlobalScopes()->where('field_id', $field->id)->where('status', '!=', CropPlan::CANCELLED)
            ->whereDate('planned_planting_on', '<=', $capturedOn)->orderByDesc('planned_planting_on')->first();
        $type = $plan?->crop_type_id ? \App\Modules\Agri\Models\CropType::withoutGlobalScopes()->find($plan->crop_type_id) : null;
        $own = \App\Modules\Agri\Models\ImageryDropRule::withoutGlobalScopes()->where('company_id', $field->company_id)->where('is_active', true)->get();
        $rules = $own->isNotEmpty()
            ? $own->map(fn ($r) => ['crop_type_id' => $r->crop_type_id, 'category' => null, 'activity_types' => $r->activity_types ?? [], 'after_harvest' => $r->after_harvest,
                'within_days' => $r->within_days, 'note' => $r->note])->all()
            : self::defaultDropRules();
        $pasture = in_array($field->land_use_type, ['pasture', 'paddock', 'camp'], true);
        foreach ($rules as $rule) {
            $applies = $rule['crop_type_id'] !== null ? (int) $rule['crop_type_id'] === (int) $type?->id
                : ($rule['category'] === null || $rule['category'] === $type?->category || ($rule['category'] === 'fodder' && $pasture));
            if (! $applies) {
                continue;
            }
            $since = $capturedOn->copy()->subDays((int) $rule['within_days'])->startOfDay();
            $act = $rule['activity_types'] ? \App\Modules\Agri\Models\FieldActivity::withoutGlobalScopes()->where('field_id', $field->id)
                ->whereIn('activity_type', $rule['activity_types'])->where('status', 'completed')
                ->whereBetween('completed_at', [$since, $capturedOn->copy()->endOfDay()])->orderByDesc('completed_at')->first() : null;
            if ($act) {
                return mb_strimwidth(sprintf('Expected drop: %s on %s, %d day(s) before this capture (rule: within %d days).',
                    str_replace('_', ' ', $act->activity_type), $act->completed_at->toDateString(), (int) $act->completed_at->copy()->startOfDay()->diffInDays($capturedOn->copy()->startOfDay()), $rule['within_days']), 0, 300);
            }
            if ($rule['after_harvest']) {
                $h = \App\Modules\Agri\Models\HarvestRecord::withoutGlobalScopes()->where('field_id', $field->id)
                    ->whereBetween('harvested_on', [$since->toDateString(), $capturedOn->toDateString()])->orderByDesc('harvested_on')->first();
                if ($h) {
                    return mb_strimwidth(sprintf('Expected drop: harvest recorded on %s, %d day(s) before this capture (rule: within %d days).',
                        $h->harvested_on->toDateString(), (int) $h->harvested_on->copy()->startOfDay()->diffInDays($capturedOn->copy()->startOfDay()), $rule['within_days']), 0, 300);
                }
            }
        }

        return null;
    }

    public function validate(ImageryFlag $flag, string $outcome, ?string $note, User $actor): ImageryFlag
    {
        if (! in_array($outcome, ['confirmed', 'false_alarm'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Say whether the field confirmed it or it was a false alarm.']);
        }
        if ($flag->validation !== 'pending') {
            throw ValidationException::withMessages(['outcome' => 'That flag has been checked.']);
        }
        $flag->update(['validation' => $outcome, 'validation_note' => $note, 'validated_by' => $actor->id, 'validated_at' => now()]);
        if ($flag->observation_id) {
            CropObservation::query()->whereKey($flag->observation_id)->update(['review_note' => ($outcome === 'confirmed' ? 'Confirmed in the field' : 'False alarm').($note ? ": {$note}" : ''),
                'status' => $outcome === 'false_alarm' ? 'closed' : 'actioned', 'closed_at' => $outcome === 'false_alarm' ? now() : null]);
        }

        return $flag;
    }

    /** How often the imagery's flags turned out to be real on this farm. */
    public function track(int $farmId): array
    {
        $flags = ImageryFlag::query()->where('farm_id', $farmId)->get();
        $checked = $flags->where('validation', '!=', 'pending');

        return ['flags' => $flags->count(), 'checked' => $checked->count(), 'confirmed' => $checked->where('validation', 'confirmed')->count(),
            'precision_pct' => $checked->count() ? round($checked->where('validation', 'confirmed')->count() / $checked->count() * 100, 1) : null,
            'label' => 'Share of checked flags confirmed in the field. Imagery points to where to look; it does not diagnose.'];
    }
}
