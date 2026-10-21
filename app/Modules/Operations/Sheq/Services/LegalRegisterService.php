<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The legal register (§5.10, SHEQ-LEG-1..4).
 *
 * The whole point of this class is the thing it refuses to do: let anybody declare themselves
 * compliant. Every status here is computed from evidence that exists elsewhere in the module — a
 * published document, an inspection schedule with nothing overdue, an unexpired competency, an
 * accepted appointment, a closed action. A line with no evidence linked reports `NOT_ASSESSED`, and
 * a line whose evidence has lapsed reports `NON_COMPLIANT` the moment it lapses, without anyone
 * remembering to update it.
 *
 * The second thing worth knowing: a line expecting three kinds of evidence and holding one is
 * `PARTIAL`, not `COMPLIANT`. GSR 3 wants a first aid box inspected, a first aider trained *and*
 * appointed; producing the inspection alone is a common way to fail an audit while believing you
 * passed.
 */
class LegalRegisterService
{
    /**
     * The pack speaks in categories of proof; the register links to concrete records. `INSPECTION`
     * in a pack means "there is a maintained inspection regime", which in this module is a schedule
     * rather than any single completed inspection — a passed inspection from last year proves
     * nothing about whether the regime still runs.
     *
     * MEETING has no module of its own yet, so it is satisfied by a minuted document. Recorded here
     * rather than silently: when a meetings module arrives, this line is what needs changing.
     */
    private const EVIDENCE_ALIASES = [
        'INSPECTION' => 'INSPECTION_SCHEDULE',
        'MEETING' => 'DOCUMENT',
    ];

    private const LINK_TYPES = [
        'DOCUMENT', 'INSPECTION_SCHEDULE', 'COMPETENCY', 'APPOINTMENT',
        'ACTION', 'RISK_ASSESSMENT', 'OBLIGATION', 'DM_DOCUMENT',
    ];

    /**
     * Evidence another module works out from its own records rather than a record linked by hand —
     * a farm's chemical registrations, its machines' statutory inspections, its operators'
     * certificates. Each is registered by the module that owns the records: a type the register's
     * clauses can name, and how to judge it for a line (which carries its site).
     *
     * @var array<string, array{label: string, evaluate: callable(int, object): array{status: string, detail: string}}>
     */
    private static array $sources = [];

    /** @param callable(int $companyId, object $line): array{status: string, detail: string} $evaluate */
    public static function registerSource(string $type, string $label, callable $evaluate): void
    {
        self::$sources[strtoupper($type)] = ['label' => $label, 'evaluate' => $evaluate];
    }

    /** @return array<string, string> evidence type => label, for the clause editor */
    public static function evidenceTypes(): array
    {
        return array_merge(array_combine(self::LINK_TYPES, array_map(fn ($t) => ucfirst(strtolower(str_replace('_', ' ', $t))), self::LINK_TYPES)),
            ['DM_DOCUMENT' => 'Document (Document Management)'], array_map(fn ($s) => $s['label'], self::$sources));
    }

    /**
     * Seed or refresh the register from a pack (SHEQ-LEG-1).
     *
     * Idempotent, and deliberately non-destructive: an existing line keeps its evidence links, its
     * responsible person and its notes. Only the pack-owned text is refreshed, and any refresh that
     * actually changes a clause raises a SHEQ-LEG-4 review flag rather than silently rewriting what
     * somebody signed off against.
     *
     * @return array{created:int, amended:int, unchanged:int, removed:int}
     */
    public function seedFromPack(int $companyId, PackManifest $pack, ?int $siteId = null): array
    {
        $clauses = $pack->legalRegister();

        if ($clauses === []) {
            throw new RuntimeException("Regulatory pack {$pack->packId} carries no legal register.");
        }

        return DB::transaction(function () use ($companyId, $pack, $siteId, $clauses) {
            $counts = ['created' => 0, 'amended' => 0, 'unchanged' => 0, 'removed' => 0];
            $seen = [];

            foreach ($clauses as $clause) {
                $key = (string) $clause['clause'];
                $seen[] = $key;

                $existing = DB::table('sheq_legal_register_lines')
                    ->where('company_id', $companyId)
                    ->where('regulatory_pack_id', $pack->packId)
                    ->where('clause', $key)
                    ->when($siteId === null,
                        fn ($q) => $q->whereNull('site_id'),
                        fn ($q) => $q->where('site_id', $siteId))
                    ->whereNull('deleted_at')
                    ->first();

                $packFields = [
                    'instrument' => (string) $clause['instrument'],
                    'requirement' => (string) $clause['requirement'],
                    'applies_to' => (string) ($clause['applies_to'] ?? 'ALL'),
                    'responsible_role' => $clause['responsible_role'] ?? null,
                    'evidence_types' => json_encode($this->normaliseEvidenceTypes($clause['evidence_type'] ?? [])),
                    'review_frequency' => $clause['review_frequency'] ?? null,
                ];

                if ($existing === null) {
                    $lineId = DB::table('sheq_legal_register_lines')->insertGetId(array_merge($packFields, [
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'clause' => $key,
                        'regulatory_pack_id' => $pack->packId,
                        'seeded_from_version' => $pack->version,
                        'site_id' => $siteId,
                        'status' => 'NOT_ASSESSED',
                        'review_due' => $this->reviewDue($packFields['review_frequency'], Carbon::today()),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    $this->recordChange($companyId, $lineId, null, $pack->version, 'ADDED',
                        ['clause' => ['from' => null, 'to' => $key]]);

                    $counts['created']++;

                    continue;
                }

                $diff = $this->diff($existing, $packFields);

                if ($diff === []) {
                    $counts['unchanged']++;

                    continue;
                }

                /**
                 * SHEQ-LEG-4. The line is flagged rather than quietly updated: somebody accepted
                 * this register against the old wording, and a changed clause may invalidate the
                 * evidence they linked.
                 */
                DB::table('sheq_legal_register_lines')->where('id', $existing->id)
                    ->update(array_merge($packFields, [
                        'seeded_from_version' => $pack->version,
                        'status' => 'REVIEW_REQUIRED',
                        'status_computed_at' => Timestamps::utc(Carbon::now()),
                        'updated_at' => now(),
                    ]));

                $this->recordChange($companyId, (int) $existing->id,
                    $existing->seeded_from_version, $pack->version, 'AMENDED', $diff);

                $counts['amended']++;
            }

            /**
             * A clause the new pack no longer carries — repealed, or moved. Not deleted: the tenant
             * may hold evidence against it and the history matters. Flagged for review instead.
             */
            $orphans = DB::table('sheq_legal_register_lines')
                ->where('company_id', $companyId)
                ->where('regulatory_pack_id', $pack->packId)
                ->whereNotIn('clause', $seen)
                ->whereNull('deleted_at')
                ->where('seeded_from_version', '!=', $pack->version)
                ->get(['id', 'clause', 'seeded_from_version']);

            foreach ($orphans as $orphan) {
                DB::table('sheq_legal_register_lines')->where('id', $orphan->id)->update([
                    'status' => 'REVIEW_REQUIRED',
                    'status_computed_at' => Timestamps::utc(Carbon::now()),
                    'updated_at' => now(),
                ]);

                $this->recordChange($companyId, (int) $orphan->id, $orphan->seeded_from_version,
                    $pack->version, 'REMOVED',
                    ['clause' => ['from' => $orphan->clause, 'to' => null]]);

                $counts['removed']++;
            }

            return $counts;
        });
    }

    /** @return array<string,array{from:mixed,to:mixed}> */
    private function diff(object $existing, array $packFields): array
    {
        $diff = [];

        foreach ($packFields as $field => $value) {
            $before = $existing->{$field};

            // jsonb comes back as a string; compare decoded so key order cannot fake a change.
            if ($field === 'evidence_types') {
                $beforeList = json_decode((string) $before, true) ?: [];
                $afterList = json_decode((string) $value, true) ?: [];
                sort($beforeList);
                sort($afterList);

                if ($beforeList !== $afterList) {
                    $diff[$field] = ['from' => $beforeList, 'to' => $afterList];
                }

                continue;
            }

            if ((string) $before !== (string) $value) {
                $diff[$field] = ['from' => $before, 'to' => $value];
            }
        }

        return $diff;
    }

    private function recordChange(int $companyId, int $lineId, ?string $from, string $to,
        string $type, array $diff): void
    {
        DB::table('sheq_legal_register_changes')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'line_id' => $lineId,
            'from_version' => $from,
            'to_version' => $to,
            'change_type' => $type,
            'diff' => json_encode($diff),
            'flagged_at' => Timestamps::utc(Carbon::now()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Attach a piece of evidence to a register line (SHEQ-LEG-2), then recompute.
     *
     * @return array<string,mixed> the line as it now stands
     */
    public function linkEvidence(int $companyId, string $lineUuid, array $input, ?int $userId = null): array
    {
        $line = $this->line($companyId, $lineUuid);
        $type = strtoupper((string) ($input['evidence_type'] ?? ''));

        if (! in_array($type, self::LINK_TYPES, true)) {
            throw new InvalidArgumentException("Unknown evidence type '{$type}'.");
        }

        $evidenceId = (int) ($input['evidence_id'] ?? 0);

        if ($evidenceId <= 0) {
            throw new InvalidArgumentException('Evidence to link must be identified.');
        }

        // A link to a record in another tenant would compute a status from data this company is not
        // entitled to see. Verified here rather than trusted from the request.
        if (! $this->evidenceBelongsToCompany($companyId, $type, $evidenceId)) {
            throw new InvalidArgumentException('That evidence does not exist in this company.');
        }

        DB::table('sheq_legal_register_evidence')->updateOrInsert(
            [
                'company_id' => $companyId, 'line_id' => $line->id,
                'evidence_type' => $type, 'evidence_id' => $evidenceId,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'evidence_label' => $input['evidence_label'] ?? null,
                'linked_by' => $userId,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $this->compute($companyId, $lineUuid);
    }

    public function unlinkEvidence(int $companyId, string $lineUuid, string $type, int $evidenceId): array
    {
        $line = $this->line($companyId, $lineUuid);

        DB::table('sheq_legal_register_evidence')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->where('evidence_type', strtoupper($type))->where('evidence_id', $evidenceId)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return $this->compute($companyId, $lineUuid);
    }

    /**
     * Rule a clause out of scope. Permitted — not every instrument applies to every employer — but
     * only with a reason, which is the difference between a scoping decision and a blank cell.
     */
    public function markNotApplicable(int $companyId, string $lineUuid, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Ruling a clause inapplicable needs a reason.');
        }

        $line = $this->line($companyId, $lineUuid);

        DB::table('sheq_legal_register_lines')->where('id', $line->id)->update([
            'is_applicable' => false,
            'not_applicable_reason' => trim($reason),
            'status' => 'NOT_APPLICABLE',
            'status_computed_at' => Timestamps::utc(Carbon::now()),
            'updated_at' => now(),
        ]);

        return $this->view($companyId, $lineUuid);
    }

    /** Acknowledge a SHEQ-LEG-4 flag, which releases the line back to computed status. */
    public function acknowledgeChange(int $companyId, string $lineUuid, ?string $notes = null,
        ?int $userId = null): array
    {
        $line = $this->line($companyId, $lineUuid);

        DB::table('sheq_legal_register_changes')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_by' => $userId,
                'reviewed_at' => Timestamps::utc(Carbon::now()),
                'review_notes' => $notes,
                'updated_at' => now(),
            ]);

        DB::table('sheq_legal_register_lines')->where('id', $line->id)->update([
            'last_reviewed_on' => Carbon::today()->toDateString(),
            'review_due' => $this->reviewDue($line->review_frequency, Carbon::today()),
            'updated_at' => now(),
        ]);

        return $this->compute($companyId, $lineUuid);
    }

    /**
     * Recompute one line's status from its evidence (SHEQ-LEG-3).
     *
     * @return array<string,mixed>
     */
    public function compute(int $companyId, string $lineUuid): array
    {
        $line = $this->line($companyId, $lineUuid);

        $evidence = DB::table('sheq_legal_register_evidence')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->whereNull('deleted_at')->get();

        $findings = [];

        foreach ($evidence as $item) {
            $finding = $this->evaluate($companyId, $item);
            $findings[] = $finding + ['evidence_type' => $item->evidence_type, 'evidence_id' => (int) $item->evidence_id];

            DB::table('sheq_legal_register_evidence')->where('id', $item->id)->update([
                'status' => $finding['status'],
                'status_detail' => $finding['detail'],
                'status_computed_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]);
        }

        $expected = json_decode((string) $line->evidence_types, true) ?: [];
        // Evidence a module works out for itself is judged directly, with nothing to link.
        foreach (array_intersect($expected, array_keys(self::$sources)) as $type) {
            $findings[] = (self::$sources[$type]['evaluate'])($companyId, $line) + ['evidence_type' => $type, 'evidence_id' => 0];
        }
        $held = array_values(array_unique(array_column($findings, 'evidence_type')));
        $missing = array_values(array_diff($expected, $held));

        $status = $this->rollUp($companyId, $line, $findings, $missing);

        DB::table('sheq_legal_register_lines')->where('id', $line->id)->update([
            'status' => $status,
            'status_computed_at' => Timestamps::utc(Carbon::now()),
            'status_detail' => json_encode([
                'findings' => $findings,
                'missing_evidence_types' => $missing,
            ]),
            'updated_at' => now(),
        ]);

        return $this->view($companyId, $lineUuid);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @param  list<string>  $missing
     */
    private function rollUp(int $companyId, object $line, array $findings, array $missing): string
    {
        if (! $line->is_applicable) {
            return 'NOT_APPLICABLE';
        }

        // An unreviewed clause change outranks everything: the evidence below it was gathered
        // against wording that no longer stands, so calling the line compliant would be a guess.
        $unreviewed = DB::table('sheq_legal_register_changes')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->whereNull('reviewed_at')
            ->where('change_type', '!=', 'ADDED')      // a newly seeded line has nothing to review
            ->exists();

        if ($unreviewed) {
            return 'REVIEW_REQUIRED';
        }

        if ($findings === []) {
            return 'NOT_ASSESSED';
        }

        $statuses = array_column($findings, 'status');

        if (in_array('NON_COMPLIANT', $statuses, true)) {
            return 'NON_COMPLIANT';
        }

        // Holding one of the three kinds of proof a clause demands is not compliance.
        if ($missing !== [] || in_array('PARTIAL', $statuses, true)) {
            return 'PARTIAL';
        }

        // A line past its own review date is not evidence of anything current.
        if ($line->review_due !== null && Carbon::parse($line->review_due)->isPast()) {
            return 'PARTIAL';
        }

        return 'COMPLIANT';
    }

    /**
     * Evaluate one piece of evidence on the terms that make it evidence.
     *
     * @return array{status:string, detail:string}
     */
    private function evaluate(int $companyId, object $item): array
    {
        $id = (int) $item->evidence_id;

        return match ($item->evidence_type) {
            'DOCUMENT' => $this->evaluateDocument($companyId, $id),
            'INSPECTION_SCHEDULE' => $this->evaluateInspectionSchedule($companyId, $id),
            'COMPETENCY' => $this->evaluateCompetency($companyId, $id),
            'APPOINTMENT' => $this->evaluateAppointment($companyId, $id),
            'ACTION' => $this->evaluateAction($companyId, $id),
            'RISK_ASSESSMENT' => $this->evaluateRiskAssessment($companyId, $id),
            'OBLIGATION' => $this->evaluateObligation($companyId, $id),
            'DM_DOCUMENT' => $this->evaluateManagedDocument($companyId, $id),
            default => ['status' => 'NOT_ASSESSED', 'detail' => 'Unknown evidence type.'],
        };
    }

    /** A Document Management document: approved, and not past its expiry. */
    private function evaluateManagedDocument(int $companyId, int $id): array
    {
        $doc = DB::table('documents')->where('company_id', $companyId)->where('id', $id)->whereNull('deleted_at')
            ->first(['title', 'status', 'approval_status', 'expiry_date']);
        if ($doc === null || $doc->status !== 'active') {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked document is no longer in force.'];
        }
        if (! in_array($doc->approval_status, ['approved', 'published'], true)) {
            return ['status' => 'PARTIAL', 'detail' => "{$doc->title} is on file but not yet approved."];
        }
        if ($doc->expiry_date !== null && Carbon::parse($doc->expiry_date)->lt(Carbon::today())) {
            return ['status' => 'NON_COMPLIANT', 'detail' => "{$doc->title} expired on {$doc->expiry_date}."];
        }

        return ['status' => 'COMPLIANT', 'detail' => "{$doc->title} is approved".($doc->expiry_date ? " and valid to {$doc->expiry_date}." : '.')];
    }

    /**
     * Record that a clause was met (reviewed) on a day: the next review falls due by its frequency.
     * Evidence is linked separately.
     */
    public function recordReview(int $companyId, string $lineUuid, string $on, ?int $userId = null): array
    {
        $line = $this->line($companyId, $lineUuid);
        DB::table('sheq_legal_register_lines')->where('id', $line->id)->update([
            'last_reviewed_on' => Carbon::parse($on)->toDateString(),
            'review_due' => $this->reviewDue($line->review_frequency, Carbon::parse($on)),
            'updated_at' => now(),
        ]);

        return $this->compute($companyId, $lineUuid);
    }

    private function evaluateDocument(int $companyId, int $id): array
    {
        $doc = DB::table('sheq_documents')->where('company_id', $companyId)->where('id', $id)
            ->whereNull('deleted_at')->first(['status', 'review_due', 'doc_number']);

        if ($doc === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked document no longer exists.'];
        }

        if ($doc->status !== 'PUBLISHED') {
            return ['status' => 'NON_COMPLIANT',
                'detail' => "{$doc->doc_number} is {$doc->status}, so nobody can be following it."];
        }

        if ($doc->review_due !== null && Carbon::parse($doc->review_due)->isPast()) {
            return ['status' => 'PARTIAL',
                'detail' => "{$doc->doc_number} is published but its review was due on {$doc->review_due}."];
        }

        return ['status' => 'COMPLIANT', 'detail' => "{$doc->doc_number} is published and in date."];
    }

    /**
     * The requirement's own example: "fire equipment serviced annually" is compliant only if the
     * schedule has nothing overdue. A schedule that was switched off is worse than one running late.
     */
    private function evaluateInspectionSchedule(int $companyId, int $id): array
    {
        $schedule = DB::table('sheq_inspection_schedules')
            ->where('company_id', $companyId)->where('id', $id)
            ->whereNull('deleted_at')->first(['is_active', 'next_due', 'last_completed']);

        if ($schedule === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked inspection schedule no longer exists.'];
        }

        if (! $schedule->is_active) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The inspection schedule is not running.'];
        }

        if ($schedule->next_due !== null && Carbon::parse($schedule->next_due)->isPast()) {
            $days = Carbon::parse($schedule->next_due)->diffInDays(Carbon::today());

            return ['status' => 'NON_COMPLIANT',
                'detail' => "The inspection has been overdue for {$days} days."];
        }

        if ($schedule->last_completed === null) {
            return ['status' => 'PARTIAL',
                'detail' => 'The schedule is running but no inspection has been completed yet.'];
        }

        return ['status' => 'COMPLIANT',
            'detail' => "Last inspected {$schedule->last_completed}, next due {$schedule->next_due}."];
    }

    private function evaluateCompetency(int $companyId, int $id): array
    {
        $records = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('competency_id', $id)
            ->whereNull('deleted_at')->whereNull('revoked_at')
            ->get(['status', 'expires_at']);

        if ($records->isEmpty()) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'Nobody holds this competency.'];
        }

        $valid = $records->filter(fn ($r) => $r->expires_at === null
            || Carbon::parse($r->expires_at)->isFuture());

        if ($valid->isEmpty()) {
            return ['status' => 'NON_COMPLIANT',
                'detail' => 'Every holder of this competency has expired.'];
        }

        if ($valid->count() < $records->count()) {
            $lapsed = $records->count() - $valid->count();

            return ['status' => 'PARTIAL',
                'detail' => "{$valid->count()} in date, {$lapsed} lapsed."];
        }

        return ['status' => 'COMPLIANT', 'detail' => "{$valid->count()} holder(s) in date."];
    }

    /**
     * A legal appointment (s16(2), s17) is a competency record carrying an appointment scope. The
     * signature matters as much as the certificate: an appointment nobody accepted in writing is
     * exactly what section 16(2) is about.
     */
    private function evaluateAppointment(int $companyId, int $id): array
    {
        $record = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('id', $id)
            ->whereNull('deleted_at')
            ->first(['appointment_scope', 'accepted_at', 'revoked_at', 'expires_at']);

        if ($record === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked appointment no longer exists.'];
        }

        if ($record->revoked_at !== null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The appointment has been revoked.'];
        }

        if (empty($record->appointment_scope)) {
            return ['status' => 'PARTIAL',
                'detail' => 'The record has no appointment scope, so it is a certificate rather than an appointment.'];
        }

        if ($record->accepted_at === null) {
            return ['status' => 'PARTIAL',
                'detail' => 'The appointment has not been accepted in writing.'];
        }

        if ($record->expires_at !== null && Carbon::parse($record->expires_at)->isPast()) {
            return ['status' => 'NON_COMPLIANT', 'detail' => "The appointment expired on {$record->expires_at}."];
        }

        return ['status' => 'COMPLIANT', 'detail' => 'Appointment in force and accepted in writing.'];
    }

    private function evaluateAction(int $companyId, int $id): array
    {
        $action = DB::table('sheq_actions')->where('company_id', $companyId)->where('id', $id)
            ->whereNull('deleted_at')->first(['status', 'due_date', 'reference']);

        if ($action === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked action no longer exists.'];
        }

        if (in_array($action->status, ['VERIFIED', 'CLOSED'], true)) {
            return ['status' => 'COMPLIANT', 'detail' => "{$action->reference} is {$action->status}."];
        }

        if ($action->due_date !== null && Carbon::parse($action->due_date)->isPast()) {
            return ['status' => 'NON_COMPLIANT',
                'detail' => "{$action->reference} was due on {$action->due_date} and is still {$action->status}."];
        }

        return ['status' => 'PARTIAL', 'detail' => "{$action->reference} is {$action->status}."];
    }

    private function evaluateRiskAssessment(int $companyId, int $id): array
    {
        $ra = DB::table('sheq_risk_assessments')->where('company_id', $companyId)->where('id', $id)
            ->whereNull('deleted_at')->first(['status', 'review_due', 'reference']);

        if ($ra === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked risk assessment no longer exists.'];
        }

        if ($ra->status !== 'APPROVED') {
            return ['status' => 'NON_COMPLIANT',
                'detail' => "{$ra->reference} is {$ra->status}; an unapproved assessment is not a control."];
        }

        if ($ra->review_due !== null && Carbon::parse($ra->review_due)->isPast()) {
            return ['status' => 'PARTIAL',
                'detail' => "{$ra->reference} is approved but was due for review on {$ra->review_due}."];
        }

        return ['status' => 'COMPLIANT', 'detail' => "{$ra->reference} is approved and in date."];
    }

    private function evaluateObligation(int $companyId, int $id): array
    {
        $obligation = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)->where('id', $id)
            ->first(['status', 'due_at', 'label']);

        if ($obligation === null) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'The linked obligation no longer exists.'];
        }

        if ($obligation->status === 'BREACHED') {
            return ['status' => 'NON_COMPLIANT', 'detail' => "{$obligation->label} was breached."];
        }

        if (in_array($obligation->status, ['SUBMITTED', 'ACKNOWLEDGED'], true)) {
            return ['status' => 'COMPLIANT', 'detail' => "{$obligation->label} was {$obligation->status}."];
        }

        if ($obligation->due_at !== null && Carbon::parse($obligation->due_at)->isPast()) {
            return ['status' => 'NON_COMPLIANT',
                'detail' => "{$obligation->label} is past its deadline and still {$obligation->status}."];
        }

        return ['status' => 'PARTIAL', 'detail' => "{$obligation->label} is {$obligation->status}."];
    }

    /**
     * Recompute the whole register. Cheap enough to run nightly: the register is tens of lines, not
     * thousands, and a status that goes stale is the failure mode this module exists to prevent.
     *
     * @return array<string,int> status => count
     */
    public function recomputeAll(int $companyId): array
    {
        $uuids = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->whereNull('deleted_at')->pluck('uuid');

        $tally = [];

        foreach ($uuids as $uuid) {
            $line = $this->compute($companyId, (string) $uuid);
            $tally[$line['status']] = ($tally[$line['status']] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * The register as an inspector would read it.
     *
     * @return list<array<string,mixed>>
     */
    public function register(int $companyId, array $filters = []): array
    {
        $query = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->whereNull('deleted_at');

        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (array_key_exists('site_id', $filters)) {
            $query->where('site_id', $filters['site_id']);
        }

        $lines = $query->orderBy('instrument')->orderBy('clause')->get();

        return array_map(fn ($line) => $this->present($companyId, $line), $lines->all());
    }

    /** @return array{lines:int, by_status:array<string,int>, compliance_percent:float} */
    public function summary(int $companyId): array
    {
        $counts = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status')->all();

        $counts = array_map('intval', $counts);
        // Clauses ruled out of scope are excluded from the denominator: a company that does no
        // construction work should not be scored down for the construction regulations.
        $assessable = array_sum($counts) - ($counts['NOT_APPLICABLE'] ?? 0);
        $compliant = $counts['COMPLIANT'] ?? 0;

        return [
            'lines' => array_sum($counts),
            'by_status' => $counts,
            'compliance_percent' => $assessable > 0
                ? round($compliant / $assessable * 100, 1)
                : 0.0,
        ];
    }

    private function view(int $companyId, string $lineUuid): array
    {
        return $this->present($companyId, $this->line($companyId, $lineUuid));
    }

    private function present(int $companyId, object $line): array
    {
        $evidence = DB::table('sheq_legal_register_evidence')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->whereNull('deleted_at')->get();

        $pendingChanges = DB::table('sheq_legal_register_changes')
            ->where('company_id', $companyId)->where('line_id', $line->id)
            ->whereNull('reviewed_at')->where('change_type', '!=', 'ADDED')
            ->orderByDesc('id')->get(['change_type', 'from_version', 'to_version', 'diff']);

        return [
            'id' => (int) $line->id,
            'uuid' => $line->uuid,
            'instrument' => $line->instrument,
            'clause' => $line->clause,
            'requirement' => $line->requirement,
            'applies_to' => $line->applies_to,
            'responsible_role' => $line->responsible_role,
            'evidence_types' => json_decode((string) $line->evidence_types, true) ?: [],
            'review_frequency' => $line->review_frequency,
            'review_due' => $line->review_due,
            'site_id' => $line->site_id ? (int) $line->site_id : null,
            'status' => $line->status,
            'status_detail' => json_decode((string) $line->status_detail, true) ?: [],
            'is_applicable' => (bool) $line->is_applicable,
            'not_applicable_reason' => $line->not_applicable_reason,
            'evidence' => array_map(fn ($e) => [
                'type' => $e->evidence_type,
                'id' => (int) $e->evidence_id,
                'label' => $e->evidence_label,
                'status' => $e->status,
                'detail' => $e->status_detail,
            ], $evidence->all()),
            'pending_changes' => array_map(fn ($c) => [
                'change_type' => $c->change_type,
                'from_version' => $c->from_version,
                'to_version' => $c->to_version,
                'diff' => json_decode((string) $c->diff, true) ?: [],
            ], $pendingChanges->all()),
        ];
    }

    private function line(int $companyId, string $lineUuid): object
    {
        $line = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->where('uuid', $lineUuid)
            ->whereNull('deleted_at')->first();

        if ($line === null) {
            throw new RuntimeException('Legal register line not found.');
        }

        return $line;
    }

    /** @return list<string> */
    private function normaliseEvidenceTypes(mixed $types): array
    {
        $list = is_array($types) ? $types : [$types];

        $normalised = array_map(function ($type) {
            $type = strtoupper((string) $type);

            return self::EVIDENCE_ALIASES[$type] ?? $type;
        }, $list);

        return array_values(array_unique(array_filter($normalised)));
    }

    private function reviewDue(?string $frequency, Carbon $from): ?string
    {
        if ($frequency === null || $frequency === '') {
            return null;
        }

        try {
            return $from->copy()->add(new \DateInterval($frequency))->toDateString();
        } catch (\Throwable) {
            // A malformed duration in a pack must not stop the register from seeding; the line
            // simply has no review date until the pack is corrected.
            return null;
        }
    }

    private function evidenceBelongsToCompany(int $companyId, string $type, int $id): bool
    {
        $table = match ($type) {
            'DOCUMENT' => 'sheq_documents',
            'INSPECTION_SCHEDULE' => 'sheq_inspection_schedules',
            'COMPETENCY' => 'sheq_competencies',
            'APPOINTMENT' => 'sheq_competency_records',
            'ACTION' => 'sheq_actions',
            'RISK_ASSESSMENT' => 'sheq_risk_assessments',
            'OBLIGATION' => 'sheq_statutory_obligations',
            'DM_DOCUMENT' => 'documents',
        };

        return DB::table($table)->where('company_id', $companyId)->where('id', $id)->exists();
    }
}
