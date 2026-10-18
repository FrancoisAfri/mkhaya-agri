<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHEQ-INC-6 — the context snapshot.
 *
 * "The single highest-value feature in the module", and the one thing a standalone SHEQ product
 * cannot do: at the moment an incident is reported, freeze what was true around it. Was the injured
 * person's ticket in date? When was that ladder last inspected? Was there an open hazard at that
 * location nobody actioned?
 *
 * Three properties matter more than completeness:
 *
 * 1. **It is taken once and never updated.** A database trigger enforces that. Its evidentiary
 *    weight comes from being contemporaneous — a snapshot that can be edited after the lawyers
 *    arrive is worth nothing.
 * 2. **A gap is recorded, not hidden.** Every compliance gap found becomes a flag that cannot be
 *    dismissed without a recorded reason. This is uncomfortable by design: the gaps it surfaces are
 *    exactly the ones an employer would rather not have written down, and exactly the ones that
 *    prevent the next incident.
 * 3. **An unavailable source is not a clean bill of health.** If LMS is unlicensed or HR is
 *    unreachable, the snapshot says so. "We could not check" and "we checked and it was fine" must
 *    never look the same in a court bundle.
 */
class ContextSnapshotBuilder
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly CompetencyLedger $competencies,
    ) {}

    /**
     * @param  list<int>  $hrPersonIds  people involved in the incident
     * @return array{snapshot:array<string,mixed>, gaps:list<array<string,mixed>>, sources:array<string,mixed>}
     */
    public function build(
        Carbon $asAt,
        array $hrPersonIds = [],
        ?int $siteId = null,
        ?int $assetId = null,
        ?int $vehicleId = null,
    ): array {
        $gaps = [];
        $sources = [];

        $people = $this->people($hrPersonIds, $gaps, $sources);
        $asset = $this->asset($assetId, $asAt, $gaps, $sources);
        $location = $this->location($siteId, $asAt, $gaps, $sources);

        return [
            'snapshot' => [
                'as_at' => $asAt->toIso8601String(),
                'people' => $people,
                'asset' => $asset,
                'location' => $location,
                'vehicle' => $vehicleId ? ['id' => $vehicleId, 'checked' => false,
                    'note' => 'Fleet adapter not yet implemented'] : null,
            ],
            'gaps' => $gaps,
            'sources' => $sources,
        ];
    }

    /**
     * Competency state per involved person. The acceptance criterion in §5.1 is precisely this:
     * an expired "Working at Heights" ticket on the injured employee must appear as a contributing
     * compliance gap, with its expiry date, on the investigation.
     */
    private function people(array $hrPersonIds, array &$gaps, array &$sources): array
    {
        $sources['employees'] = [
            'adapter' => class_basename($this->employees),
            'available' => $this->employees->isAvailable(),
        ];
        $sources['competencies'] = [
            'adapter' => class_basename($this->competencies),
            'available' => $this->competencies->isAvailable(),
        ];

        if ($hrPersonIds === []) {
            return [];
        }

        if (! $this->employees->isAvailable()) {
            // Unknown, not fine. Recorded as a gap so nobody later reads silence as compliance.
            $gaps[] = [
                'type' => 'SOURCE_UNAVAILABLE',
                'subject' => 'employees',
                'severity' => 'INFO',
                'detail' => 'Employee directory was unavailable when this incident was reported; '
                            .'competency status could not be verified.',
            ];

            return [];
        }

        $directory = $this->employees->findMany($hrPersonIds);
        $people = [];

        foreach ($hrPersonIds as $hrPersonId) {
            $person = $directory[$hrPersonId] ?? null;
            $competencies = $this->competencies->isAvailable()
                ? $this->competencies->statusFor($hrPersonId)
                : [];

            foreach ($competencies as $competency) {
                if ($competency['status'] === CompetencyLedger::EXPIRED) {
                    $gaps[] = [
                        'type' => 'COMPETENCY_EXPIRED',
                        'subject' => 'person:'.$hrPersonId,
                        'severity' => ($competency['blocks_work'] ?? false) ? 'CRITICAL' : 'HIGH',
                        'detail' => sprintf(
                            '%s held an expired "%s" competency (expired %s, %d days before the incident).',
                            $person['full_name'] ?? ('Person #'.$hrPersonId),
                            $competency['competency'],
                            $competency['expires_at'] ?? 'unknown',
                            abs((int) ($competency['days_until_expiry'] ?? 0)),
                        ),
                        'expires_at' => $competency['expires_at'],
                    ];
                }
            }

            $people[] = [
                'hr_person_id' => $hrPersonId,
                'employee' => $person,
                'competencies' => $competencies,
                'competency_source_available' => $this->competencies->isAvailable(),
            ];
        }

        return $people;
    }

    /**
     * Last inspection of a linked asset. The §5.1 acceptance criterion wants an overdue inspection
     * surfaced with how overdue it was — "400 days ago against a 90-day schedule".
     */
    private function asset(?int $assetId, Carbon $asAt, array &$gaps, array &$sources): ?array
    {
        if ($assetId === null) {
            return null;
        }

        $available = Schema::hasTable('assets');
        $sources['assets'] = ['adapter' => 'assets table', 'available' => $available];

        if (! $available) {
            return ['id' => $assetId, 'checked' => false];
        }

        $asset = DB::table('assets')->where('id', $assetId)
            ->first(['id', 'uuid', 'name', 'asset_tag', 'serial_number', 'status', 'asset_status']);

        if ($asset === null) {
            return ['id' => $assetId, 'found' => false];
        }

        // SHEQ owns no inspection history for assets yet — the Inspections feature will supply it.
        // Saying "not yet available" is the honest answer; a green tick here would be a lie with a
        // timestamp on it.
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'status' => $asset->asset_status ?? $asset->status,
            'last_inspection' => null,
            'inspection_history_available' => false,
        ];
    }

    /**
     * What was already known to be wrong at this location when the incident happened.
     *
     * An open hazard reported at the same site before the incident, or an overdue action that was
     * supposed to fix something there, is the most damning and most useful fact an investigation
     * can have. Both raise gaps — an overdue action especially, because it means the organisation
     * had already identified the problem and not closed it.
     */
    private function location(?int $siteId, Carbon $asAt, array &$gaps, array &$sources): ?array
    {
        if ($siteId === null) {
            return null;
        }

        $site = DB::table('sheq_sites')->where('id', $siteId)->first(['id', 'name', 'site_type', 'timezone']);

        $hazardsAvailable = Schema::hasTable('sheq_hazards');
        $actionsAvailable = Schema::hasTable('sheq_actions');
        $sources['hazards'] = ['adapter' => 'sheq_hazards', 'available' => $hazardsAvailable];
        $sources['actions'] = ['adapter' => 'sheq_actions', 'available' => $actionsAvailable];

        $openHazards = [];
        if ($hazardsAvailable) {
            // Reported before the incident and not yet controlled — as at occurred_at, not now.
            $openHazards = DB::table('sheq_hazards')
                ->where('site_id', $siteId)
                ->whereIn('status', ['OPEN', 'TRIAGED'])
                ->where('reported_at', '<=', $asAt)
                ->whereNull('deleted_at')
                ->orderByDesc('reported_at')->limit(20)
                ->get(['uuid', 'reference', 'description', 'hazard_category', 'severity_est',
                    'status', 'reported_at', 'triage_due_at'])
                ->map(fn ($h) => (array) $h)->all();

            foreach ($openHazards as $hazard) {
                // An untriaged hazard past its SLA is its own finding.
                if ($hazard['triage_due_at'] && Carbon::parse($hazard['triage_due_at'])->lt($asAt)
                    && $hazard['status'] === 'OPEN') {
                    $gaps[] = [
                        'type' => 'HAZARD_UNTRIAGED',
                        'subject' => 'hazard:'.$hazard['reference'],
                        'severity' => 'HIGH',
                        'detail' => sprintf(
                            'Hazard %s at this location was reported on %s and had not been triaged '
                            .'by the time of this incident.',
                            $hazard['reference'],
                            Carbon::parse($hazard['reported_at'])->toDateString(),
                        ),
                    ];
                }
            }
        }

        $openActions = [];
        if ($actionsAvailable) {
            $openActions = DB::table('sheq_actions')
                ->where('site_id', $siteId)
                ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'PENDING_VERIFICATION'])
                ->whereNull('deleted_at')
                ->orderBy('due_date')->limit(20)
                ->get(['uuid', 'reference', 'title', 'priority', 'status', 'due_date', 'hierarchy_level'])
                ->map(fn ($a) => (array) $a)->all();

            foreach ($openActions as $action) {
                if (Carbon::parse($action['due_date'])->lt($asAt)) {
                    $gaps[] = [
                        'type' => 'ACTION_OVERDUE',
                        'subject' => 'action:'.$action['reference'],
                        'severity' => in_array($action['priority'], ['CRITICAL', 'HIGH'], true) ? 'CRITICAL' : 'HIGH',
                        'detail' => sprintf(
                            'Corrective action %s ("%s", %s priority) was due %s and still open at '
                            .'the time of this incident.',
                            $action['reference'], $action['title'], strtolower($action['priority']),
                            Carbon::parse($action['due_date'])->toDateString(),
                        ),
                    ];
                }
            }
        }

        return [
            'site' => $site ? (array) $site : null,
            'open_hazards' => $hazardsAvailable ? $openHazards : null,
            'open_actions' => $actionsAvailable ? $openActions : null,
            'risk_assessment' => null,   // HIRA not built yet — recorded as unknown, not as absent
            'active_permit' => null,   // Permit to Work not built yet
        ];
    }
}
