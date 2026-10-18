<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Exceptions\NoActiveRegulatoryPack;
use App\Modules\Operations\Sheq\Regulatory\Classifier;
use App\Modules\Operations\Sheq\Regulatory\MetricConventions;
use App\Modules\Operations\Sheq\Regulatory\ObligationScheduler;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Security\SpecialInformation;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reporting an incident (SHEQ-INC-1, INC-4, INC-6, REG-5).
 *
 * One transaction does all of it: persist the incident, classify it against the pack version
 * effective at `occurred_at`, instantiate the statutory obligations with their clocks running,
 * freeze the context snapshot, and append the audit entry. Either the whole statutory position
 * exists or none of it does — an incident recorded without its notification deadline is the exact
 * failure this module exists to prevent.
 *
 * SHEQ-INC-1 caps mandatory input at four fields: what happened, when, where, and type. Everything
 * else is enrichment. A reporter with 60 seconds and a cracked phone screen must be able to file.
 */
class IncidentReporter
{
    /**
     * Potential severity → days to reach approved findings, when a tenant has configured nothing.
     *
     * A map rather than a single number, because a single number is how every incident ends up
     * with the same deadline and the severity rating stops deciding anything. Tenants override it
     * in settings; this is what applies before they have.
     */
    private const DEFAULT_INVESTIGATION_SLA = [5 => 7, 4 => 7, 3 => 14, 2 => 30, 1 => 30];

    /** An unrated incident is not a harmless one, so it takes the shortest deadline. */
    private const DEFAULT_INVESTIGATION_DAYS = 7;

    public function __construct(
        private readonly Classifier $classifier,
        private readonly ObligationScheduler $scheduler,
        private readonly ContextSnapshotBuilder $context,
        private readonly NotificationRouter $notifications,
        private readonly EventPublisher $events,
        private readonly SpecialInformation $special,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{incident_id:int, uuid:string, reference:string, classification:array<string,mixed>, obligations:list<array<string,mixed>>, gaps:list<array<string,mixed>>}
     */
    public function report(int $companyId, array $input, ?int $reportedBy = null): array
    {
        $occurredAt = Carbon::parse($input['occurred_at'] ?? now());
        $siteId = $input['site_id'] ?? null;

        // SHEQ-REG-1/2: the pack effective for this site at the time of the incident — not the
        // newest one installed, and not the one active today.
        $pack = $this->packFor($companyId, $siteId, $occurredAt);

        $facts = $this->facts($input);
        $classification = $this->classifier->classify($pack, $facts);
        $metrics = new MetricConventions($pack);

        return DB::transaction(function () use (
            $companyId, $input, $occurredAt, $siteId, $reportedBy, $pack, $classification, $metrics
        ) {
            // Offline replay returns the original rather than filing a duplicate (SHEQ-OFF-2).
            if (! empty($input['client_id'])) {
                $existing = DB::table('sheq_incidents')
                    ->where('company_id', $companyId)->where('client_id', $input['client_id'])
                    ->first(['id', 'uuid', 'reference']);

                if ($existing) {
                    return [
                        'incident_id' => $existing->id, 'uuid' => $existing->uuid,
                        'reference' => $existing->reference, 'classification' => $classification->toArray(),
                        'obligations' => [], 'gaps' => [], 'replayed' => true,
                    ];
                }
            }

            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId, $occurredAt);
            $isAnonymous = (bool) ($input['is_anonymous'] ?? false);

            $retentionYears = $metrics->retentionYears(
                $classification->reportable ? 'incident_s24' : 'incident'
            );

            $incidentId = DB::table('sheq_incidents')->insertGetId([
                'company_id' => $companyId,
                'uuid' => $uuid,
                'reference' => $reference,
                'client_id' => $input['client_id'] ?? null,
                'site_id' => $siteId,
                'occurred_at' => Timestamps::utc($occurredAt),
                'reported_at' => now(),
                'discovered_at' => Timestamps::utc($input['discovered_at'] ?? null),
                'location_text' => $input['location_text'] ?? null,
                'location_lat' => $input['location_lat'] ?? null,
                'location_lng' => $input['location_lng'] ?? null,
                'incident_type' => $input['incident_type'] ?? 'NEAR_MISS',
                'activity_at_time' => $input['activity_at_time'] ?? null,
                'description' => $input['description'] ?? '',
                'immediate_action' => $input['immediate_action'] ?? null,
                'outcome' => $input['outcome'] ?? null,
                'treatment_level' => $input['treatment_level'] ?? null,
                'actual_severity' => $input['actual_severity'] ?? null,
                'potential_severity' => $input['potential_severity'] ?? null,
                'likely_incapacity_days' => $input['likely_incapacity_days'] ?? null,
                'dangerous_substance_release' => (bool) ($input['dangerous_substance_release'] ?? false),
                'machinery_failure_causing_danger' => (bool) ($input['machinery_failure_causing_danger'] ?? false),

                'regulatory_pack_id' => $classification->packId,
                'regulatory_pack_version' => $classification->packVersion,
                'classification_rule_id' => $classification->ruleId,
                'statutory_category' => $classification->statutoryCategory,
                'is_reportable' => $classification->reportable,
                'required_forms' => json_encode($classification->forms),

                'status' => 'REPORTED',
                'is_confidential' => (bool) ($input['is_confidential'] ?? false),
                'is_anonymous' => $isAnonymous,
                'linked_asset_id' => $input['linked_asset_id'] ?? null,
                'linked_vehicle_id' => $input['linked_vehicle_id'] ?? null,
                'source_channel' => $input['source_channel'] ?? 'WEB',
                // SHEQ-INC-3: an anonymous report stores no reporter, and no device or IP either.
                'reported_by' => $isAnonymous ? null : $reportedBy,

                /*
                 * The clock starts now, not when somebody gets round to reading it.
                 *
                 * Starting it at acknowledgement would make it resettable by not looking, which is
                 * backwards: the longer an incident goes unread the more urgent it is. Nothing is
                 * assigned yet — an owner is chosen at triage, by a person — and that is the state
                 * the sweep watches for.
                 */
                'investigation_due_at' => Timestamps::utc(
                    $this->investigationDueAt($companyId, $input['potential_severity'] ?? null)
                ),
                'retention_until' => $occurredAt->copy()->addYears($retentionYears)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $people = $this->attachPeople($companyId, $incidentId, (array) ($input['people'] ?? []));

            // SHEQ-INC-6 — frozen at occurred_at, written once.
            $context = $this->context->build(
                asAt: $occurredAt,
                hrPersonIds: $people,
                siteId: $siteId,
                assetId: $input['linked_asset_id'] ?? null,
                vehicleId: $input['linked_vehicle_id'] ?? null,
            );

            DB::table('sheq_incident_context')->insert([
                'company_id' => $companyId,
                'incident_id' => $incidentId,
                'captured_at' => now(),
                'as_at' => Timestamps::utc($occurredAt),
                'snapshot' => json_encode($context['snapshot']),
                'compliance_gaps' => json_encode($context['gaps']),
                'gap_count' => count($context['gaps']),
                'sources' => json_encode($context['sources']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $obligations = $this->scheduler->schedule(
                $pack, $classification->obligations, $occurredAt, $this->timezoneFor($siteId)
            );

            foreach ($obligations as $draft) {
                DB::table('sheq_statutory_obligations')->insert([
                    'company_id' => $companyId,
                    'uuid' => (string) Str::uuid(),
                    'obligation_def_id' => $draft->definitionId,
                    'label' => $draft->label,
                    'subject_type' => 'INCIDENT',
                    'subject_id' => $incidentId,
                    'regulatory_pack_id' => $classification->packId,
                    'regulatory_pack_version' => $classification->packVersion,
                    'anchor_at' => Timestamps::utc($draft->anchorAt),
                    'due_at' => Timestamps::utc($draft->dueAt),
                    'reminders' => json_encode(array_map(fn ($r) => $r->toIso8601String(), $draft->reminderAt)),
                    'escalations' => json_encode(array_map(fn ($e) => [
                        'after' => $e['after']->toIso8601String(),
                        'notify_role' => $e['notify_role'], 'severity' => $e['severity'],
                    ], $draft->escalations)),
                    'status' => 'PENDING',
                    'responsible_role' => $draft->responsibleRole,
                    'recipient' => $draft->recipient,
                    'evidence_required' => $draft->evidenceRequired,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /**
             * SHEQ-INC-4: notify per the escalation matrix, in the same transaction. Routing only
             * writes dispatch rows — cheap, and safely inside the reporter's 2-second confirmation
             * budget — while delivery happens in the sender. Doing it here rather than after commit
             * is what stops an incident existing with nobody routed to it.
             */
            $routed = $this->notifications->route($companyId, 'incident.reported', [
                'subject_type' => 'INCIDENT',
                'subject_id' => $incidentId,
                'reference' => $reference,
                'title' => Str::limit((string) ($input['description'] ?? ''), 120),
                'incident_type' => $input['incident_type'] ?? null,
                'outcome' => $input['outcome'] ?? null,
                'actual_severity' => $input['actual_severity'] ?? null,
                'potential_severity' => $input['potential_severity'] ?? null,
                'site_id' => $siteId,
                'statutory_category' => $classification->statutoryCategory,
                'is_reportable' => $classification->reportable,
                'link' => '/sheq/incidents/'.$uuid,
            ]);

            /**
             * §8.2. Published in the same transaction as the incident so the two cannot diverge:
             * an event announcing a rolled-back write, or a write whose event was lost, are the
             * failures the outbox exists to prevent (SHEQ-ARC-1).
             */
            $this->events->publish($companyId, 'sheq.incident.reported', 'INCIDENT', $incidentId, [
                'incident_id' => $incidentId,
                'reference' => $reference,
                'site_id' => $siteId,
                'type' => $input['incident_type'] ?? null,
                'actual_severity' => $input['actual_severity'] ?? null,
                'potential_severity' => $input['potential_severity'] ?? null,
                'statutory_category' => $classification->statutoryCategory,
                'is_reportable' => $classification->reportable,
                'occurred_at' => Timestamps::utc($occurredAt)->toIso8601String(),
            ], [
                // The reporter is deliberately absent on an anonymous report (SHEQ-INC-3) — a
                // subscriber must not be able to identify them from the event either.
                'actor_id' => $isAnonymous ? null : $reportedBy,
                'anonymous' => $isAnonymous,
            ]);

            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => $isAnonymous ? null : $reportedBy,
                'actor_type' => 'USER',
                'action' => 'incident.reported',
                'subject_type' => 'Incident',
                'subject_id' => $incidentId,
                'after' => json_encode([
                    'reference' => $reference,
                    'statutory_category' => $classification->statutoryCategory,
                    'reportable' => $classification->reportable,
                    'pack' => $classification->packId.'@'.$classification->packVersion,
                    'obligations' => count($obligations),
                    'compliance_gaps' => count($context['gaps']),
                    'notifications' => $routed['dispatched'],
                    'escalation_floor' => $routed['floor_applied'],
                ]),
            ]);

            return [
                'incident_id' => $incidentId,
                'uuid' => $uuid,
                'reference' => $reference,
                'classification' => $classification->toArray(),
                'obligations' => array_map(fn ($d) => $d->toArray(), $obligations),
                'gaps' => $context['gaps'],
                'notifications' => $routed,
            ];
        });
    }

    /** The facts the pack's rules are written against (§3.2). */
    private function facts(array $input): array
    {
        return [
            'incident_type' => $input['incident_type'] ?? null,
            'outcome' => $input['outcome'] ?? null,
            'treatment_level' => $input['treatment_level'] ?? null,
            'likely_incapacity_days' => $input['likely_incapacity_days'] ?? null,
            'actual_severity' => $input['actual_severity'] ?? null,
            'potential_severity' => $input['potential_severity'] ?? null,
            'dangerous_substance_release' => (bool) ($input['dangerous_substance_release'] ?? false),
            'machinery_failure_causing_danger' => (bool) ($input['machinery_failure_causing_danger'] ?? false),
            'uncontrolled_release_under_pressure' => (bool) ($input['uncontrolled_release_under_pressure'] ?? false),
        ];
    }

    /** @return list<int> hr person ids, for the context snapshot */
    private function attachPeople(int $companyId, int $incidentId, array $people): array
    {
        $hrPersonIds = [];

        foreach ($people as $person) {
            // Injury details are POPIA s26 special personal information and are encrypted at
            // rest. `external_name` deliberately is not: it is needed to match a data subject
            // request to the records it concerns, and an encrypted name cannot be searched.
            DB::table('sheq_incident_people')->insert($this->special->protectRow([
                'company_id' => $companyId,
                'uuid' => (string) Str::uuid(),
                'incident_id' => $incidentId,
                'role' => $person['role'] ?? 'WITNESS',
                'hr_person_id' => $person['hr_person_id'] ?? null,
                'external_name' => $person['external_name'] ?? null,
                'external_contact' => $person['external_contact'] ?? null,
                'body_part' => $person['body_part'] ?? null,
                'injury_nature' => $person['injury_nature'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if (! empty($person['hr_person_id'])) {
                $hrPersonIds[] = (int) $person['hr_person_id'];
            }
        }

        return array_values(array_unique($hrPersonIds));
    }

    /**
     * The pack version effective for this site at this moment (SHEQ-REG-1/2). Site activation wins
     * over the company-wide one; PREVIEW activations are ignored because a sandbox evaluation must
     * never write real obligations (SHEQ-REG-8).
     */
    private function packFor(int $companyId, ?int $siteId, Carbon $at): PackManifest
    {
        $activation = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'ACCEPTED')
            ->whereNull('a.deactivated_at')
            ->when($siteId, fn ($q) => $q->where(fn ($w) => $w->where('a.site_id', $siteId)->orWhereNull('a.site_id')))
            ->where('v.effective_from', '<=', $at->toDateString())
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $at->toDateString()))
            // A site-specific activation beats the company default.
            ->orderByRaw('a.site_id is null')
            ->orderByDesc('v.effective_from')
            ->first(['v.manifest']);

        if ($activation === null) {
            // Renders itself: a browser gets the form back with the reason and its input intact,
            // a device gets a 409 the offline queue can record. It used to be a bare
            // RuntimeException, so in production this was a 500 and the reporter lost what they
            // had typed with nothing telling them what to do about it.
            throw new NoActiveRegulatoryPack($siteId);
        }

        return PackManifest::fromArray(json_decode($activation->manifest, true));
    }

    private function timezoneFor(?int $siteId): string
    {
        return $siteId
            ? (string) (DB::table('sheq_sites')->where('id', $siteId)->value('timezone') ?? 'Africa/Johannesburg')
            : 'Africa/Johannesburg';
    }

    /** Per-company, per-year sequence: INC-2026-000123. */
    private function nextReference(int $companyId, Carbon $occurredAt): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('incident_prefix') ?? 'INC';
        $year = $occurredAt->year;

        $last = DB::table('sheq_incidents')
            ->where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    /**
     * When an approved investigation must exist by, from how bad this could have been.
     *
     * Potential rather than actual, for the same reason the pack picks the method that way: what
     * decides how hard something must be investigated is what nearly happened, not the luck of
     * what did. An unrated incident is not a harmless one, so it takes the shortest deadline until
     * somebody rates it.
     */
    private function investigationDueAt(int $companyId, mixed $potentialSeverity): Carbon
    {
        $configured = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('investigation_sla_days');

        $days = is_string($configured) ? json_decode($configured, true) : (array) $configured;
        $days = is_array($days) ? $days : [];

        $severity = $potentialSeverity === null ? null : (int) $potentialSeverity;

        if ($severity === null) {
            return Carbon::now()->addDays(self::DEFAULT_INVESTIGATION_DAYS);
        }

        $forThis = $days[$severity]
            ?? $days[(string) $severity]
            ?? self::DEFAULT_INVESTIGATION_SLA[$severity]
            ?? self::DEFAULT_INVESTIGATION_DAYS;

        return Carbon::now()->addDays((int) $forThis);
    }
}
