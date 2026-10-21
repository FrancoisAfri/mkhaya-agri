<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\ObservationPhoto;
use App\Modules\Agri\Models\ScoutingThreshold;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §3.3: scouting from the field — what was seen, where, how much, with photos — and what
 * happens next: a recommended action with a person and a follow-up date, threshold alerts when
 * observations add up, escalation when nobody acts, and the treatment that answered it.
 *
 * An AI may suggest what a problem could be; it never decides. A suggestion carries its own
 * uncertainty, and an observation with one cannot be answered with a treatment until a qualified
 * person has reviewed it.
 */
class Scouting
{
    public const SEVERITY_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(private readonly AgriNotifier $notifier) {}

    /** @param list<array{name: string, mime: string, data: string}> $photos */
    public function record(Farm $farm, ?FarmField $field, ?CropPlan $plan, array $data, array $photos, User $actor): CropObservation
    {
        if (! empty($data['follow_up_on']) && empty($data['responsible_user_id'])) {
            throw ValidationException::withMessages(['responsible_user_id' => 'Name who follows it up.']);
        }

        return DB::transaction(function () use ($farm, $field, $plan, $data, $photos, $actor) {
            $obs = CropObservation::create(array_intersect_key($data, array_flip(['observed_on', 'kind', 'severity', 'growth_stage', 'description',
                'action_required', 'latitude', 'longitude', 'affected_area_ha', 'incidence_pct', 'recommended_action', 'responsible_user_id', 'follow_up_on']))
                + ['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $field?->id ?? $plan?->field_id, 'crop_plan_id' => $plan?->id,
                    'status' => 'open', 'recorded_by' => $actor->id]);
            foreach (array_slice($photos, 0, 6) as $p) {
                ObservationPhoto::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'observation_id' => $obs->id,
                    'name' => $p['name'], 'mime' => $p['mime'], 'data' => $p['data'], 'uploaded_by' => $actor->id]);
            }
            $this->checkThresholds($obs);

            return $obs;
        });
    }

    /**
     * Whether this observation, with the others on its field, crosses a threshold. When it does, the
     * observation needs action and the farm's leads are told — once per observation.
     *
     * @return list<string> the thresholds crossed
     */
    public function checkThresholds(CropObservation $obs): array
    {
        if (! $obs->field_id) {
            return [];
        }
        $cropType = $obs->crop_plan_id ? CropPlan::withoutGlobalScopes()->whereKey($obs->crop_plan_id)->value('crop_type_id') : null;
        $crossed = [];
        $rules = ScoutingThreshold::query()->where('is_active', true)->where('kind', $obs->kind)
            ->where(fn ($q) => $q->whereNull('crop_type_id')->when($cropType, fn ($q) => $q->orWhere('crop_type_id', $cropType)))->get();
        foreach ($rules as $rule) {
            $min = self::SEVERITY_ORDER[$rule->min_severity] ?? 2;
            $count = CropObservation::query()->where('field_id', $obs->field_id)->where('kind', $obs->kind)->where('status', '!=', 'closed')
                ->whereDate('observed_on', '>=', $obs->observed_on->copy()->subDays($rule->within_days - 1))
                ->get()->filter(fn ($o) => (self::SEVERITY_ORDER[$o->severity] ?? 0) >= $min
                    && ($rule->min_incidence_pct === null || (float) $o->incidence_pct >= $rule->min_incidence_pct))->count();
            if ($count >= $rule->count) {
                $crossed[] = "{$count} {$obs->kind} observation(s) at {$rule->min_severity} or worse within {$rule->within_days} day(s)";
            }
        }
        if ($crossed && ! $obs->action_required) {
            $obs->update(['action_required' => true, 'follow_up_on' => $obs->follow_up_on ?? today()->addDay()]);
            $this->notifier->scouting($obs, 'Threshold reached: '.implode('; ', $crossed));
        }

        return $crossed;
    }

    /**
     * Observations needing action that nobody has answered in time are escalated to the farm's
     * managers (and the owner, through the farm alerts) — once each.
     */
    public function escalateOverdue(int $companyId): int
    {
        $n = 0;
        $rules = ScoutingThreshold::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->get();
        $obs = CropObservation::withoutGlobalScopes()->where('company_id', $companyId)->where('action_required', true)
            ->whereIn('status', ['open'])->whereNull('escalated_at')->whereNull('treatment_activity_id')->get();
        foreach ($obs as $o) {
            $hours = $rules->where('kind', $o->kind)->min('escalate_after_hours') ?? 48;
            $due = $o->follow_up_on ? $o->follow_up_on->copy()->endOfDay() : $o->created_at->copy()->addHours($hours);
            if ($due->isPast() || $o->created_at->copy()->addHours($hours)->isPast()) {
                $o->update(['escalated_at' => now()]);
                $this->notifier->scouting($o, 'Escalated: no action recorded since '.$o->observed_on->toDateString(), true);
                $n++;
            }
        }

        return $n;
    }

    /** A qualified person's view of the AI's suggestion: accepted, corrected or rejected, in their words. */
    public function review(CropObservation $obs, string $note, User $actor): CropObservation
    {
        if (! $obs->ai_suggestion) {
            throw ValidationException::withMessages(['review' => 'There is no suggestion to review.']);
        }
        $obs->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => mb_strimwidth($note, 0, 500)]);

        return $obs;
    }

    /** The treatment that answered an observation. A suggestion from the AI must have been reviewed first. */
    public function linkTreatment(CropObservation $obs, FieldActivity $activity): CropObservation
    {
        if ($obs->ai_suggestion && ! $obs->reviewed_at) {
            throw ValidationException::withMessages(['treatment' => 'A qualified person must review the suggested diagnosis before a treatment is recorded against it.']);
        }
        if ((int) $activity->farm_id !== (int) $obs->farm_id) {
            throw ValidationException::withMessages(['treatment' => 'That activity is on another farm.']);
        }
        $obs->update(['treatment_activity_id' => $activity->id, 'status' => 'actioned']);

        return $obs;
    }

    public function close(CropObservation $obs, ?string $note, User $actor): CropObservation
    {
        $obs->update(['status' => 'closed', 'closed_at' => now(), 'review_note' => $obs->review_note ?? $note]);

        return $obs;
    }
}
