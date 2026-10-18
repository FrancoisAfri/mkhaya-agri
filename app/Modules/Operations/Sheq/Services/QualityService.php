<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Q in SHEQ (QLT-1 to QLT-8).
 *
 * The module was a safety system with quality mentioned in passing. An inspection could record a
 * score; nothing could record that the concrete failed its 28-day strength, who owed the rework,
 * what it cost, or that the same defect had already happened four times on the same floor.
 *
 * That asymmetry is not cosmetic. Quality failures and safety failures on a site have the same
 * causes — rushed work, the wrong competency, a procedure nobody read — so a system that counts
 * one and not the other produces a contractor score that is half an answer.
 *
 * Two rules in here are the ones that make it a control rather than a filing cabinet:
 *
 * **A high-severity NCR cannot be closed by whoever did the work (QLT-7).** Self-verification is
 * the single commonest way a quality system produces clean records and defective work, and it is
 * never malicious: the person who fixed it is the person who knows it is fixed, so they tick the
 * box. The record then says the defect was independently checked when it was not.
 *
 * **Repeat defects are counted on a code, never on prose.** "Honeycombing on the column again" is
 * unfindable; the same defect against a code, a location and a contractor is countable. QLT-8's
 * repeat-defect figure is worth nothing without that, and the vocabulary is what makes it work.
 */
class QualityService
{
    /** QLT-1's five kinds, which are one shape: a requirement, its source, and its test. */
    public const REQUIREMENT_TYPES = [
        'OBJECTIVE' => 'Quality objective',
        'STANDARD' => 'Standard',
        'SPECIFICATION' => 'Specification',
        'PROJECT_REQUIREMENT' => 'Project requirement',
        'ACCEPTANCE_CRITERION' => 'Acceptance criterion',
    ];

    /** The disciplines QLT-2 names, used for templates, requirements and NCRs alike. */
    public const DISCIPLINES = [
        'CONCRETE' => 'Concrete',
        'STEEL' => 'Structural steel',
        'WELDING' => 'Welding',
        'ELECTRICAL' => 'Electrical',
        'PLUMBING' => 'Plumbing',
        'WATERPROOFING' => 'Waterproofing',
        'FINISHES' => 'Finishes',
        'MATERIALS' => 'Materials',
    ];

    public const MINOR = 'MINOR';

    public const MAJOR = 'MAJOR';

    public const CRITICAL = 'CRITICAL';

    public const SEVERITIES = [self::MINOR, self::MAJOR, self::CRITICAL];

    /**
     * The severities QLT-7 calls high.
     *
     * MAJOR as well as CRITICAL, deliberately. A rule that applied only to the rarest category
     * would be a rule nobody ever encountered — and MAJOR is where the arguable cases live,
     * which is exactly where an independent pair of eyes earns its keep.
     */
    public const HIGH_SEVERITIES = [self::MAJOR, self::CRITICAL];

    public const OPEN = 'OPEN';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const AWAITING_VERIFICATION = 'AWAITING_VERIFICATION';

    public const CLOSED = 'CLOSED';

    public const VOID = 'VOID';

    /**
     * A starting defect vocabulary, by discipline.
     *
     * The thing QLT-8's repeat-defect figure depends on. Shipped so a host has something to
     * record against on day one, and editable afterwards because the defects that recur on a
     * particular job are specific to it.
     *
     * @var array<string, list<array{code:string, name:string}>>
     */
    public const SHIPPED_DEFECTS = [
        'CONCRETE' => [
            ['code' => 'CONC_STRENGTH_FAIL', 'name' => 'Cube strength below specification'],
            ['code' => 'CONC_HONEYCOMB', 'name' => 'Honeycombing or voids'],
            ['code' => 'CONC_COVER', 'name' => 'Insufficient cover to reinforcement'],
            ['code' => 'CONC_CRACKING', 'name' => 'Cracking beyond tolerance'],
            ['code' => 'CONC_FINISH', 'name' => 'Surface finish out of tolerance'],
            ['code' => 'CONC_CURING', 'name' => 'Curing not carried out as specified'],
        ],
        'STEEL' => [
            ['code' => 'STEEL_ALIGNMENT', 'name' => 'Member out of alignment or plumb'],
            ['code' => 'STEEL_BOLT_TORQUE', 'name' => 'Bolts not torqued as specified'],
            ['code' => 'STEEL_COATING', 'name' => 'Protective coating damaged or missing'],
            ['code' => 'STEEL_MATERIAL_CERT', 'name' => 'Material certificate missing'],
        ],
        'WELDING' => [
            ['code' => 'WELD_POROSITY', 'name' => 'Porosity'],
            ['code' => 'WELD_UNDERCUT', 'name' => 'Undercut'],
            ['code' => 'WELD_INCOMPLETE_FUSION', 'name' => 'Incomplete fusion or penetration'],
            ['code' => 'WELD_PROFILE', 'name' => 'Weld profile or size out of specification'],
            ['code' => 'WELD_UNQUALIFIED', 'name' => 'Welder not qualified for the procedure'],
        ],
        'ELECTRICAL' => [
            ['code' => 'ELEC_EARTHING', 'name' => 'Earthing or bonding incorrect'],
            ['code' => 'ELEC_TERMINATION', 'name' => 'Termination not to specification'],
            ['code' => 'ELEC_CABLE_ROUTE', 'name' => 'Cable routing or support incorrect'],
            ['code' => 'ELEC_COC_MISSING', 'name' => 'Certificate of compliance outstanding'],
        ],
        'PLUMBING' => [
            ['code' => 'PLUMB_PRESSURE_FAIL', 'name' => 'Pressure test failed'],
            ['code' => 'PLUMB_FALL', 'name' => 'Incorrect fall or gradient'],
            ['code' => 'PLUMB_LEAK', 'name' => 'Leak at joint or fitting'],
        ],
        'WATERPROOFING' => [
            ['code' => 'WP_FLOOD_TEST_FAIL', 'name' => 'Flood test failed'],
            ['code' => 'WP_DETAIL', 'name' => 'Upstand or detail not as specified'],
            ['code' => 'WP_SUBSTRATE', 'name' => 'Substrate not prepared'],
        ],
        'FINISHES' => [
            ['code' => 'FIN_LEVEL_TOLERANCE', 'name' => 'Level or flatness out of tolerance'],
            ['code' => 'FIN_DAMAGE', 'name' => 'Damage to completed finishes'],
            ['code' => 'FIN_COLOUR', 'name' => 'Colour or texture mismatch'],
        ],
        'MATERIALS' => [
            ['code' => 'MAT_UNAPPROVED', 'name' => 'Unapproved material used'],
            ['code' => 'MAT_CERT_MISSING', 'name' => 'Material certificate or batch record missing'],
            ['code' => 'MAT_STORAGE', 'name' => 'Material stored incorrectly'],
            ['code' => 'MAT_DAMAGED', 'name' => 'Material damaged or out of date'],
        ],
    ];

    /** Why work had to be done again (QLT-4), grouped so the figure can be read. */
    public const REWORK_REASONS = [
        'DEFECTIVE_WORK' => 'Work not done to specification',
        'DAMAGE_BY_OTHERS' => 'Damaged by a following trade',
        'DESIGN_CHANGE' => 'Design or instruction changed',
        'WRONG_MATERIAL' => 'Wrong material used',
        'MISSING_INFORMATION' => 'Work done without the current information',
        'SEQUENCE_ERROR' => 'Done out of sequence',
    ];

    public function __construct(private readonly ActionService $actions) {}

    // ── QLT-1 ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{id:int, uuid:string, reference:string} */
    public function saveRequirement(int $companyId, ?string $uuid, array $input,
        ?int $actorId = null): array
    {
        $type = strtoupper(trim((string) ($input['requirement_type'] ?? '')));

        if (! isset(self::REQUIREMENT_TYPES[$type])) {
            throw new RuntimeException('A quality requirement has to say which kind it is.');
        }

        $values = [
            'requirement_type' => $type,
            'title' => trim((string) $input['title']),
            'description' => $input['description'] ?? null,
            'source' => $input['source'] ?? null,
            'acceptance_criteria' => $input['acceptance_criteria'] ?? null,
            'target_value' => $input['target_value'] ?? null,
            'target_unit' => $input['target_unit'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'discipline' => empty($input['discipline']) ? null
                : strtoupper(trim((string) $input['discipline'])),
            'owner_id' => $input['owner_id'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? true),
            'updated_at' => now(),
        ];

        if ($uuid !== null) {
            $existing = DB::table('sheq_quality_requirements')
                ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
                ->first(['id', 'reference']);

            if ($existing === null) {
                throw new RuntimeException('No such quality requirement in this company.');
            }

            DB::table('sheq_quality_requirements')->where('id', $existing->id)->update($values);

            $this->audit($companyId, $actorId, 'quality.requirement_changed', (int) $existing->id,
                ['reference' => $existing->reference]);

            return ['id' => (int) $existing->id, 'uuid' => $uuid,
                'reference' => $existing->reference];
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_quality_requirements', 'QR');

        $id = (int) DB::table('sheq_quality_requirements')->insertGetId($values + [
            'uuid' => $uuid, 'company_id' => $companyId, 'reference' => $reference,
            'created_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.requirement_created', $id,
            ['reference' => $reference, 'type' => $type]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /** @return list<array<string,mixed>> */
    public function requirements(int $companyId, bool $activeOnly = false): array
    {
        return DB::table('sheq_quality_requirements as r')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'r.site_id')
            ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
            ->when($activeOnly, fn ($q) => $q->where('r.is_active', true))
            ->orderBy('r.requirement_type')->orderBy('r.title')
            ->get(['r.id', 'r.uuid', 'r.reference', 'r.requirement_type', 'r.title',
                'r.description', 'r.source', 'r.acceptance_criteria', 'r.target_value',
                'r.target_unit', 'r.discipline', 'r.is_active', 's.name as site_name'])
            ->map(fn ($r) => (array) $r)->all();
    }

    // ── QLT-3 ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Raise a non-conformance.
     *
     * @return array{id:int, uuid:string, reference:string}
     */
    public function raiseNcr(int $companyId, array $input, ?int $actorId = null): array
    {
        $severity = strtoupper(trim((string) ($input['severity'] ?? self::MINOR)));

        if (! in_array($severity, self::SEVERITIES, true)) {
            $severity = self::MINOR;
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId, 'sheq_ncrs', 'NCR');

        $id = (int) DB::table('sheq_ncrs')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'quality_requirement_id' => $input['quality_requirement_id'] ?? null,
            'requirement_text' => $input['requirement_text'] ?? null,
            'defect_code' => empty($input['defect_code']) ? null
                : strtoupper(trim((string) $input['defect_code'])),
            'defect' => $input['defect'] ?? null,
            'discipline' => empty($input['discipline']) ? null
                : strtoupper(trim((string) $input['discipline'])),
            'site_id' => $input['site_id'] ?? null,
            'location_text' => $input['location_text'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
            'responsible_id' => $input['responsible_id'] ?? null,
            'inspection_id' => $input['inspection_id'] ?? null,
            'severity' => $severity,
            /*
             * A due date by default, from the severity. An NCR with no due date is an NCR nobody
             * is late on, and the overdue figure is the only thing that makes the register move.
             */
            'due_date' => $input['due_date'] ?? Carbon::today()->addDays(match ($severity) {
                self::CRITICAL => 3,
                self::MAJOR => 7,
                default => 21,
            })->toDateString(),
            'status' => self::OPEN,
            'raised_by' => $actorId,
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.ncr_raised', $id, [
            'reference' => $reference, 'severity' => $severity,
            'defect_code' => $input['defect_code'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /**
     * Raise the corrective action (QLT-6).
     *
     * Through `ActionService`, because the requirement says "the existing unified CAPA system" in
     * so many words — and because an action raised from an NCR then gets the same overdue sweep,
     * the same effectiveness review and the same screens as one raised from an incident.
     *
     * @return array<string,mixed>
     */
    public function raiseAction(int $companyId, string $uuid, array $input,
        ?int $actorId = null): array
    {
        $ncr = $this->findNcr($companyId, $uuid);

        if ($ncr->action_id !== null) {
            throw new RuntimeException(sprintf(
                'A corrective action has already been raised from %s.', $ncr->reference));
        }

        $action = $this->actions->create($companyId, [
            'action_type' => 'CORRECTIVE',
            'title' => $input['title'] ?? sprintf('%s: %s', $ncr->reference,
                Str::limit((string) ($ncr->defect ?: 'non-conformance'), 120)),
            'description' => $input['description'] ?? $ncr->defect,
            'source_type' => 'NCR',
            'source_id' => (int) $ncr->id,
            'site_id' => $ncr->site_id,
            'contractor_id' => $ncr->contractor_id,
            'assigned_to' => $input['assigned_to'] ?? $ncr->responsible_id,
            'due_date' => $input['due_date'] ?? $ncr->due_date,
            'priority' => $ncr->severity === self::CRITICAL ? 'HIGH'
                : ($ncr->severity === self::MAJOR ? 'MEDIUM' : 'LOW'),
            'hierarchy_level' => $input['hierarchy_level'] ?? null,
        ], $actorId);

        DB::table('sheq_ncrs')->where('id', $ncr->id)->update([
            'action_id' => $action['id'],
            'status' => self::IN_PROGRESS,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.ncr_action_raised', (int) $ncr->id,
            ['action' => $action['reference'] ?? null]);

        return $action;
    }

    /** Record that the corrective work has been done, and who did it. */
    public function recordCorrection(int $companyId, string $uuid, array $input,
        ?int $actorId = null): void
    {
        $ncr = $this->findNcr($companyId, $uuid);

        if (in_array($ncr->status, [self::CLOSED, self::VOID], true)) {
            throw new RuntimeException(sprintf('%s is already closed.', $ncr->reference));
        }

        DB::table('sheq_ncrs')->where('id', $ncr->id)->update([
            'corrected_by' => $input['corrected_by'] ?? $actorId,
            'corrected_at' => now(),
            'correction_notes' => $input['correction_notes'] ?? null,
            /*
             * Straight to awaiting verification rather than to closed, whatever the severity.
             * A minor NCR can be closed by the same person in the next step — but it still
             * passes through a state that says the work is done and unchecked, because that is
             * the list a quality manager walks on a Friday.
             */
            'status' => self::AWAITING_VERIFICATION,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.ncr_corrected', (int) $ncr->id,
            ['corrected_by' => $input['corrected_by'] ?? $actorId]);
    }

    /**
     * Why this NCR cannot be closed by this person (QLT-7), or null.
     *
     * A sentence rather than a boolean, because the refusal has to explain itself: somebody
     * blocked from closing their own work and told only "not permitted" assumes the system is
     * broken and asks an administrator to do it for them, which produces exactly the record the
     * rule exists to prevent.
     */
    public function verificationBlocker(object $ncr, ?int $verifierId): ?string
    {
        if (! in_array($ncr->severity, self::HIGH_SEVERITIES, true)) {
            return null;
        }

        if ($ncr->corrected_by === null) {
            return 'Record who carried out the corrective work before verifying it.';
        }

        if ($verifierId !== null && (int) $verifierId === (int) $ncr->corrected_by) {
            /*
             * The heart of QLT-7, and it is never malicious: the person who fixed it is the
             * person who knows it is fixed, so they tick the box. The record then says the defect
             * was independently checked when it was not — and that record is the defence against
             * a claim years later.
             */
            return sprintf(
                'A %s non-conformance cannot be verified by the person who did the corrective '
                .'work. Somebody else has to check it.',
                strtolower($ncr->severity));
        }

        return null;
    }

    /** Verify and close. */
    public function verify(int $companyId, string $uuid, array $input, ?int $actorId = null): void
    {
        $ncr = $this->findNcr($companyId, $uuid);

        if ($ncr->status === self::CLOSED) {
            throw new RuntimeException(sprintf('%s is already closed.', $ncr->reference));
        }

        $blocker = $this->verificationBlocker($ncr, $actorId);

        if ($blocker !== null) {
            throw new RuntimeException($blocker);
        }

        DB::table('sheq_ncrs')->where('id', $ncr->id)->update([
            'verified_by' => $actorId,
            'verified_at' => now(),
            'verification_notes' => $input['verification_notes'] ?? null,
            'status' => self::CLOSED,
            'closed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.ncr_verified', (int) $ncr->id, [
            'corrected_by' => $ncr->corrected_by, 'verified_by' => $actorId,
            // Both ids in the record, so an auditor can see the separation was real rather than
            // asserted.
            'independent' => $ncr->corrected_by !== null
                && (int) $ncr->corrected_by !== (int) $actorId,
        ]);
    }

    public function voidNcr(int $companyId, string $uuid, string $reason, ?int $actorId = null): void
    {
        $ncr = $this->findNcr($companyId, $uuid);

        DB::table('sheq_ncrs')->where('id', $ncr->id)->update([
            'status' => self::VOID,
            'void_reason' => $reason,
            'closed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.ncr_voided', (int) $ncr->id,
            ['reason' => $reason]);
    }

    // ── QLT-5 ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Open a root-cause investigation into a non-conformance.
     *
     * Through `InvestigationService`, which already enforces 5-Why, Fishbone and ICAM with a
     * minimum number of causes per method, a required depth, and the rule that an individual's
     * mistake is not a root cause. A second engine for quality would mean two sets of those
     * rules, and within a release they would disagree about what an adequate investigation is.
     *
     * The severity maps onto the 1-5 depth scale the method table is keyed on, so a critical
     * non-conformance gets an ICAM rather than three whys — which is exactly the mapping the
     * incident side makes from potential severity.
     *
     * @return array<string,mixed>
     */
    public function investigate(int $companyId, string $uuid, ?string $method = null,
        ?int $actorId = null): array
    {
        $ncr = $this->findNcr($companyId, $uuid);

        $depth = match ($ncr->severity) {
            self::CRITICAL => 5,
            self::MAJOR => 3,
            default => 2,
        };

        $investigation = app(InvestigationService::class)->openForSubject(
            $companyId, 'NCR', (int) $ncr->id, $depth, $method, $actorId);

        $this->audit($companyId, $actorId, 'quality.ncr_investigation_opened', (int) $ncr->id,
            ['method' => $investigation['method'], 'required' => $investigation['required_method']]);

        return $investigation;
    }

    /**
     * The investigation into a non-conformance, where there is one.
     *
     * @return array<string,mixed>|null
     */
    public function investigation(int $companyId, string $uuid): ?array
    {
        $ncr = $this->findNcr($companyId, $uuid);

        return app(InvestigationService::class)->forSubject($companyId, 'NCR', (int) $ncr->id);
    }

    // ── QLT-4 ─────────────────────────────────────────────────────────────────────────────────

    /** Record a bout of rework. */
    public function recordRework(int $companyId, array $input, ?int $actorId = null): int
    {
        $ncrId = null;

        if (! empty($input['ncr_uuid'])) {
            $ncrId = (int) $this->findNcr($companyId, (string) $input['ncr_uuid'])->id;
        }

        $id = (int) DB::table('sheq_rework')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'ncr_id' => $ncrId,
            'description' => trim((string) $input['description']),
            'reason_code' => empty($input['reason_code']) ? null
                : strtoupper(trim((string) $input['reason_code'])),
            'reason' => $input['reason'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
            /*
             * Money in minor units. A float is wrong by a cent somewhere, and this figure ends up
             * in a commercial argument where being wrong by a cent is the whole argument.
             */
            'cost_minor' => isset($input['cost'])
                ? (int) round(((float) $input['cost']) * 100) : null,
            'cost_currency' => strtoupper((string) ($input['cost_currency'] ?? 'ZAR')),
            'hours' => $input['hours'] ?? null,
            'occurred_on' => $input['occurred_on'] ?? Carbon::today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.rework_recorded', $id, [
            'cost_minor' => isset($input['cost'])
                ? (int) round(((float) $input['cost']) * 100) : null,
            'contractor_id' => $input['contractor_id'] ?? null,
        ]);

        return $id;
    }

    /** Confirm the redone work was checked (QLT-4's verification). */
    public function verifyRework(int $companyId, string $uuid, ?string $notes = null,
        ?int $actorId = null): void
    {
        $row = DB::table('sheq_rework')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id']);

        if ($row === null) {
            throw new RuntimeException('No such rework record in this company.');
        }

        DB::table('sheq_rework')->where('id', $row->id)->update([
            'is_verified' => true,
            'verified_by' => $actorId,
            'verified_at' => now(),
            'verification_notes' => $notes,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'quality.rework_verified', (int) $row->id, []);
    }

    // ── Reading ───────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function ncrs(int $companyId, array $filters = [], int $limit = 400): array
    {
        return DB::table('sheq_ncrs as n')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'n.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'n.contractor_id')
            ->leftJoin('sheq_quality_requirements as r', 'r.id', '=', 'n.quality_requirement_id')
            ->leftJoin('sheq_actions as a', 'a.id', '=', 'n.action_id')
            ->where('n.company_id', $companyId)->whereNull('n.deleted_at')
            ->when(! empty($filters['status']), fn ($q) => $q->where('n.status', $filters['status']))
            ->when(! empty($filters['severity']), fn ($q) => $q->where('n.severity', $filters['severity']))
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('n.site_id', $filters['site_id']))
            ->orderByRaw("case n.status when 'OPEN' then 0 when 'IN_PROGRESS' then 1
                when 'AWAITING_VERIFICATION' then 2 else 3 end")
            ->orderByRaw("case n.severity when 'CRITICAL' then 0 when 'MAJOR' then 1 else 2 end")
            ->orderByDesc('n.id')->limit($limit)
            ->get(['n.uuid', 'n.reference', 'n.defect_code', 'n.defect', 'n.discipline',
                'n.location_text', 'n.severity', 'n.status', 'n.due_date', 'n.raised_at',
                'n.corrected_by', 'n.corrected_at', 'n.verified_by', 'n.verified_at',
                'n.requirement_text', 'n.void_reason', 's.name as site_name',
                'c.name as contractor_name', 'r.reference as requirement_reference',
                'r.title as requirement_title', 'a.reference as action_reference'])
            ->map(fn ($n) => array_merge((array) $n, [
                'overdue' => $n->due_date !== null
                    && ! in_array($n->status, [self::CLOSED, self::VOID], true)
                    && Carbon::parse($n->due_date)->isPast(),
                'high_severity' => in_array($n->severity, self::HIGH_SEVERITIES, true),
                // Shown as a fact, so an auditor reading the register can see where the
                // separation held without opening each one.
                'independently_verified' => $n->verified_by !== null
                    && $n->corrected_by !== null
                    && (int) $n->verified_by !== (int) $n->corrected_by,
            ]))->all();
    }

    /** @return list<array<string,mixed>> */
    public function rework(int $companyId, array $filters = [], int $limit = 300): array
    {
        return DB::table('sheq_rework as w')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'w.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->leftJoin('sheq_ncrs as n', 'n.id', '=', 'w.ncr_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('w.site_id', $filters['site_id']))
            ->orderByDesc('w.occurred_on')->orderByDesc('w.id')->limit($limit)
            ->get(['w.uuid', 'w.description', 'w.reason_code', 'w.reason', 'w.cost_minor',
                'w.cost_currency', 'w.hours', 'w.occurred_on', 'w.is_verified',
                's.name as site_name', 'c.name as contractor_name', 'n.reference as ncr_reference'])
            ->map(fn ($w) => array_merge((array) $w, [
                'cost' => $w->cost_minor === null ? null : round($w->cost_minor / 100, 2),
            ]))->all();
    }

    public function findNcr(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            throw new RuntimeException('No such non-conformance in this company.');
        }

        return $row;
    }

    private function nextReference(int $companyId, string $table, string $prefix): string
    {
        $last = DB::table($table)->where('company_id', $companyId)
            ->orderByDesc('id')->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last) + 1);

        return sprintf('%s-%04d', $prefix, $next);
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqQuality',
            'subject_id' => $subjectId,
            'after' => json_encode($after),
        ]);
    }
}
