<?php

namespace App\Modules\Agri\Services;

use App\Core\AI\Base\BaseModuleAI;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;

/**
 * Phase 2 §3.3: possible diagnoses for a scouting observation, each with how sure the model is —
 * a starting point for a qualified person, never a decision. Metered like every AI call
 * (BaseModuleAI), within the organisation's AI quota.
 *
 * The AI layer takes text only, so the suggestion is drawn from the scout's description, the crop,
 * its stage and the region; the photos are for the reviewer. The suggestion says so.
 */
class CropDiagnosisAI extends BaseModuleAI
{
    protected string $module = 'agri';

    public function suggest(CropObservation $obs): array
    {
        $plan = $obs->crop_plan_id ? CropPlan::query()->with(['cropType', 'variety'])->find($obs->crop_plan_id) : null;
        $farm = $obs->farm;
        $data = $this->askForJson(
            'Suggest what this crop problem could be. Reply as JSON: {"diagnoses":[{"name":string,"confidence":"low"|"medium"|"high","why":string,'
            .'"look_for":string}],"caveats":string,"urgent":boolean}. Give at most four, most likely first. Be honest about uncertainty; '
            .'never recommend a product or a rate.',
            ['crop' => $plan?->cropType?->name, 'variety' => $plan?->variety?->name, 'growth_stage' => $obs->growth_stage ?? $plan?->current_stage,
                'kind' => $obs->kind, 'severity' => $obs->severity, 'incidence_pct' => $obs->incidence_pct, 'description' => $obs->description,
                'region' => $farm?->location, 'month' => $obs->observed_on?->format('F')],
            ['task' => 'agri.diagnose'],
            'You help farm scouts in southern Africa think about crop pest and disease problems. You suggest possibilities with honest '
            .'confidence; a qualified agronomist decides. You never prescribe chemicals, rates or withholding periods.',
        );

        $suggestion = [
            'diagnoses' => array_slice(array_values(array_filter(array_map(fn ($d) => is_array($d) && ! empty($d['name']) ? [
                'name' => mb_strimwidth((string) $d['name'], 0, 120), 'confidence' => in_array($d['confidence'] ?? '', ['low', 'medium', 'high'], true) ? $d['confidence'] : 'low',
                'why' => mb_strimwidth((string) ($d['why'] ?? ''), 0, 400), 'look_for' => mb_strimwidth((string) ($d['look_for'] ?? ''), 0, 300)] : null,
                (array) ($data['diagnoses'] ?? [])))), 0, 4),
            'caveats' => mb_strimwidth((string) ($data['caveats'] ?? ''), 0, 600),
            'urgent' => (bool) ($data['urgent'] ?? false),
            'basis' => 'Drawn from the written description, crop, stage and region. Photos were not analysed.',
            'at' => now()->toIso8601String(),
        ];
        // A new suggestion needs a fresh review.
        $obs->update(['ai_suggestion' => $suggestion, 'reviewed_by' => null, 'reviewed_at' => null]);

        return $suggestion;
    }
}
