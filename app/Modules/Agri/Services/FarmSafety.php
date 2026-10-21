<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Operations\Sheq\Exceptions\NoActiveRegulatoryPack;
use App\Modules\Operations\Sheq\Services\HazardService;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use App\Modules\Operations\Sheq\Services\InspectionGates;
use App\Modules\Operations\Sheq\Services\InspectionService;
use App\Modules\Operations\Sheq\Services\SiteProvisioner;
use App\Modules\Operations\Sheq\Services\WasteAndSpillService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §12.2: farm safety through SHEQ.
 *
 * A farm is a SHEQ site. What a worker reports from the farm goes into SHEQ's own workflow — a
 * hazard to the hazard register; an injury, near miss, spill or unsafe machine as an incident,
 * classified against the organisation's regulatory pack with its statutory clock running — so the
 * containment, investigation, corrective actions, approvals and closure are SHEQ's, not a second
 * copy. A chemical spill is also a spill record (with whether the authority must be told); an
 * unsafe machine is also a fault that stops it.
 *
 * Risky work is gated by SHEQ inspections: a SHEQ inspection template named as a gate for a
 * category of job must be carried out for the job, and pass, before the job starts.
 */
class FarmSafety
{
    public const KINDS = ['hazard', 'near_miss', 'injury', 'chemical_spill', 'unsafe_equipment'];

    public function site(Farm $farm, ?User $actor = null): int
    {
        if ($farm->sheq_site_id && DB::table('sheq_sites')->where('id', $farm->sheq_site_id)->exists()) {
            return (int) $farm->sheq_site_id;
        }
        $uuid = app(SiteProvisioner::class)->createManualSite((int) $farm->company_id, ['name' => $farm->name, 'code' => 'FARM-'.$farm->code, 'site_type' => 'FARM',
            'timezone' => $farm->timezone ?: 'Africa/Johannesburg', 'external_reference' => "agri_farm:{$farm->id}"], $actor?->id);
        $id = (int) DB::table('sheq_sites')->where('uuid', $uuid)->value('id');
        $farm->forceFill(['sheq_site_id' => $id])->saveQuietly();

        return $id;
    }

    /** @return array{kind: string, reference: string, uuid: string, link: string, also: list<string>} */
    public function report(Farm $farm, array $data, User $actor): array
    {
        if (! in_array($data['kind'], self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Say what is being reported.']);
        }
        $site = $this->site($farm, $actor);
        $base = ['site_id' => $site, 'description' => $data['description'], 'location_text' => $data['location'] ?? $farm->name,
            'location_lat' => $data['latitude'] ?? null, 'location_lng' => $data['longitude'] ?? null,
            // SHEQ keys replays on a uuid; a phone's own id is turned into a stable one.
            'client_id' => empty($data['client_id']) ? null : (\Illuminate\Support\Str::isUuid($data['client_id']) ? $data['client_id']
                : \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_OID, "agri-safety:{$farm->company_id}:{$data['client_id']}")->toString()),
            'is_anonymous' => (bool) ($data['anonymous'] ?? false)];

        if ($data['kind'] === 'hazard') {
            $h = app(HazardService::class)->report((int) $farm->company_id, $base + ['hazard_category' => $data['category'] ?? null, 'severity_est' => $data['severity'] ?? null], $actor->id);

            return ['kind' => 'hazard', 'reference' => $h['reference'], 'uuid' => $h['uuid'], 'link' => "/sheq/hazards/{$h['uuid']}", 'also' => []];
        }

        $also = [];
        $type = match ($data['kind']) { 'injury' => 'INJURY', 'chemical_spill' => 'ENVIRONMENTAL', default => 'NEAR_MISS' };

        try {
            return DB::transaction(function () use ($farm, $data, $actor, $site, $base, $type, &$also) {
                $machine = ! empty($data['machine_id']) ? FarmMachine::query()->where('farm_id', $farm->id)->where('uuid', $data['machine_id'])->first() : null;
                $inc = app(IncidentReporter::class)->report((int) $farm->company_id, $base + [
                    'occurred_at' => $data['occurred_at'] ?? now()->toIso8601String(), 'incident_type' => $type,
                    'activity_at_time' => $data['activity'] ?? null, 'immediate_action' => $data['immediate_action'] ?? null,
                    'outcome' => $data['outcome'] ?? ($data['kind'] === 'injury' ? null : 'NO_INJURY'), 'treatment_level' => $data['treatment_level'] ?? null,
                    'potential_severity' => $data['severity'] ?? null, 'dangerous_substance_release' => $data['kind'] === 'chemical_spill',
                    'machinery_failure_causing_danger' => $data['kind'] === 'unsafe_equipment', 'source_channel' => 'AGRI',
                ], $actor->id);
                if ($data['kind'] === 'chemical_spill') {
                    $sp = app(WasteAndSpillService::class)->recordSpill((int) $farm->company_id, ['substance' => $data['substance'] ?? 'Unknown chemical', 'is_hazardous' => true,
                        'quantity' => $data['quantity'] ?? null, 'unit' => $data['unit'] ?? 'L', 'site_id' => $site, 'location_text' => $base['location_text'],
                        'receiving_environment' => $data['receiving_environment'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null, 'cause' => $data['description'],
                        'containment' => $data['immediate_action'] ?? null], $actor->id);
                    $also[] = "Spill {$sp['reference']}".($sp['notifiable'] ? ' — the authority must be notified' : '');
                }
                if ($data['kind'] === 'unsafe_equipment' && $machine) {
                    $f = app(MachineMaintenance::class)->report($machine, 'major', "Reported unsafe ({$inc['reference']}): {$data['description']}", true, $actor, 'report');
                    $also[] = "{$machine->code} taken out of service".($f->affected_work_orders ? '; '.count($f->affected_work_orders).' booked job(s) flagged' : '');
                }

                return ['kind' => $data['kind'], 'reference' => $inc['reference'], 'uuid' => $inc['uuid'], 'link' => "/sheq/incidents/{$inc['uuid']}", 'also' => $also,
                    'reportable' => $inc['classification']['reportable'] ?? null];
            });
        } catch (NoActiveRegulatoryPack $e) {
            throw ValidationException::withMessages(['kind' => 'Incidents are classified against a regulatory pack, and none is active for this organisation. Activate one in SHEQ → Regulatory packs; a hazard can still be reported.']);
        }
    }

    /** The farm's incidents, hazards and spills, from SHEQ. */
    public function register(Farm $farm): array
    {
        if (! $farm->sheq_site_id) {
            return ['incidents' => [], 'hazards' => [], 'spills' => []];
        }
        $site = (int) $farm->sheq_site_id;

        return [
            'incidents' => DB::table('sheq_incidents')->where('company_id', $farm->company_id)->where('site_id', $site)->whereNull('deleted_at')->where('is_confidential', false)
                ->orderByDesc('occurred_at')->limit(100)->get(['uuid', 'reference', 'incident_type', 'description', 'occurred_at', 'status', 'is_reportable', 'statutory_category', 'investigation_due_at'])->all(),
            'hazards' => DB::table('sheq_hazards')->where('company_id', $farm->company_id)->where('site_id', $site)->orderByDesc('reported_at')->limit(100)
                ->get(['uuid', 'reference', 'description', 'hazard_category', 'status', 'reported_at'])->all(),
            'spills' => DB::table('sheq_spills')->where('company_id', $farm->company_id)->where('site_id', $site)->whereNull('deleted_at')->orderByDesc('occurred_at')->limit(50)
                ->get(['uuid', 'reference', 'substance', 'quantity', 'unit', 'status', 'authority_notifiable', 'authority_notified'])->all(),
        ];
    }

    // ── job safety checks: SHEQ inspections that gate the job ───────────────────────────────

    /** The SHEQ inspection templates a job of this category must pass before it starts. */
    public function requiredFor(WO $wo): array
    {
        return app(InspectionGates::class)->required((int) $wo->company_id, InspectionGates::SUBJECT_WORK_ORDER, (string) $wo->category);
    }

    /**
     * Carry out a gating inspection for a job, through SHEQ (scored by the template, failures raising
     * SHEQ actions as the template says).
     *
     * @param  array<string, mixed>  $responses  question id => {value, comment}
     */
    public function inspect(WO $wo, string $templateCode, array $responses, User $actor, ?string $clientId = null): array
    {
        $template = collect($this->requiredFor($wo))->firstWhere('code', $templateCode)
            ?? throw ValidationException::withMessages(['template' => 'That inspection does not gate this job.']);
        $farm = Farm::withoutGlobalScopes()->findOrFail($wo->farm_id);

        return app(InspectionService::class)->complete((int) $wo->company_id, ['template_id' => $template->id, 'site_id' => $this->site($farm, $actor),
            'subject_type' => InspectionGates::SUBJECT_WORK_ORDER, 'subject_id' => $wo->id, 'client_id' => $clientId], $responses, $actor->id);
    }

    /** Throws if the job may not start yet: a gating inspection not done, or done and failed. */
    public function assertMayStart(WO $wo): void
    {
        $blocking = app(InspectionGates::class)->blocking((int) $wo->company_id, InspectionGates::SUBJECT_WORK_ORDER, (string) $wo->category, (int) $wo->id);
        if ($blocking) {
            throw ValidationException::withMessages(['to' => "{$wo->reference}: ".implode(' ', $blocking)]);
        }
    }
}
