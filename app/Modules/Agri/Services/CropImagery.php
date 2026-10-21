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

    public function import(FarmField $field, array $data, User $actor): array
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
                    'image_data' => $data['image_data'] ?? null, 'change_pct' => $change, 'recorded_by' => $actor->id]);
            $flag = null;
            if ($prev && (float) ($data['cloud_pct'] ?? 0) <= self::MAX_CLOUD) {
                $prevCells = collect($prev->cells ?? [])->keyBy('id');
                $dropped = collect($data['cells'] ?? [])->filter(function ($c) use ($prevCells) {
                    $before = (float) ($prevCells[$c['id']]['value'] ?? 0);

                    return $before > 0 && ((float) $c['value'] - $before) / $before * 100 <= -self::DROP_PCT;
                })->values();
                if (($change !== null && $change <= -self::DROP_PCT) || $dropped->isNotEmpty()) {
                    $why = $change !== null && $change <= -self::DROP_PCT
                        ? strtoupper($data['index'])." fell {$change}% across {$field->code} since {$prev->captured_on->toDateString()}"
                        : $dropped->count().' zone(s) of '.$field->code.' fell more than '.self::DROP_PCT.'% since '.$prev->captured_on->toDateString();
                    $first = $dropped->first();
                    $obs = CropObservation::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'crop_plan_id' => $plan?->id,
                        'observed_on' => today(), 'kind' => 'plant_health', 'severity' => 'medium', 'status' => 'open', 'action_required' => true,
                        'description' => "Imagery ({$data['source']}): {$why}. Go and look — imagery shows where, not what.", 'latitude' => $first['lat'] ?? null, 'longitude' => $first['lng'] ?? null,
                        'recorded_by' => $actor->id]);
                    $flag = ImageryFlag::create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'imagery_id' => $img->id, 'field_id' => $field->id,
                        'reason' => mb_strimwidth($why, 0, 300), 'cells' => $dropped->all() ?: null, 'observation_id' => $obs->id]);
                }
            }

            return ['imagery' => $img, 'flag' => $flag, 'change_pct' => $change];
        });
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
