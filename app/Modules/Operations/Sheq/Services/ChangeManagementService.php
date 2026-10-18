<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Changes, their consequences, and the gate between the two (MOC-1 to MOC-5).
 *
 * Every other register in this module records something that has happened. This is the only one
 * about something that has not happened yet, which is the whole of its value: it is the only
 * place a safety system acts before the consequences rather than after them.
 *
 * What it exists to prevent is specific and extremely common — a change made competently, by
 * people who knew what they were doing, whose knock-on effects nobody traced. A pump replaced
 * with a larger one, and the hot-work permit limits are now wrong. A chemical substituted, and
 * four SDSs, two procedures and the spill plan are now wrong. A contractor replaced, and their
 * safety file, inductions and site approvals belong to a firm that has left. Nothing failed;
 * something was not followed through.
 *
 * Three things here are load-bearing, and dropping any one of them turns this into a form:
 *
 * **The gate (MOC-4).** A change cannot be marked implemented while a mandatory control is
 * outstanding. A register of changes with a checklist nobody has to finish is a register of
 * changes.
 *
 * **Approval authority follows risk (MOC-3).** The risk rating is the approval workflow's
 * subtype, so a host configures "who approves a critical change" once. Segregation of duties is
 * not implemented here at all — `ApprovalService` already refuses to let a requester approve
 * their own request at any step, whatever roles they hold, and a second implementation of that
 * rule is a second place for it to be wrong.
 *
 * **The review has a date (MOC-5).** "We will review it" with no date is the same as no review.
 */
class ChangeManagementService
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const IMPLEMENTED = 'IMPLEMENTED';

    public const REVIEWED = 'REVIEWED';

    public const CANCELLED = 'CANCELLED';

    /** The approval subject type, so a host can configure a workflow against it. */
    public const SUBJECT = 'MANAGEMENT_OF_CHANGE';

    /**
     * The nine kinds of change MOC-1 lists.
     *
     * Declared so the screen can label them and a test can assert the requirement is covered. A
     * category missing from here is a change people make and record somewhere else, which is the
     * same as not recording it.
     *
     * @var array<string,string>
     */
    public const CATEGORIES = [
        'EQUIPMENT' => 'Equipment or plant',
        'PROCESS' => 'Process',
        'CHEMICAL' => 'Chemical or substance',
        'CONTRACTOR' => 'Contractor',
        'SITE' => 'Site or layout',
        'ORGANISATION' => 'Organisation or people',
        'LEGISLATION' => 'Legislation or standard',
        'WORK_METHOD' => 'Work method',
        'OPERATING_HOURS' => 'Operating hours',
    ];

    /** Permanent, temporary, or made under pressure. */
    public const NATURES = [
        'PERMANENT' => 'Permanent',
        'TEMPORARY' => 'Temporary',
        /*
         * An emergency change is made first and documented immediately after, because the
         * alternative is a plant left in an unsafe state while somebody raises paperwork. It is
         * marked so the review cannot be skipped — an emergency change is the one most likely to
         * have had its consequences untraced, and the one a regulator asks about.
         */
        'EMERGENCY' => 'Emergency — made under pressure',
    ];

    public const RISK_RATINGS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** How long after implementation the effectiveness review falls due, by risk. */
    private const REVIEW_DAYS = ['LOW' => 90, 'MEDIUM' => 60, 'HIGH' => 30, 'CRITICAL' => 14];

    public function __construct(
        private readonly ChangeImpactDetector $detector,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * Register a change, and work out what it invalidates (MOC-1, MOC-2).
     *
     * @return array{id:int, uuid:string, reference:string, impacts:int}
     */
    public function register(int $companyId, array $input, ?int $actorId = null): array
    {
        $category = strtoupper(trim((string) ($input['change_category'] ?? '')));

        if (! isset(self::CATEGORIES[$category])) {
            throw new RuntimeException('A change has to say what kind of change it is.');
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId);

        $id = (int) DB::table('sheq_changes')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'title' => trim((string) $input['title']),
            'description' => $input['description'] ?? null,
            'change_category' => $category,
            'change_nature' => isset(self::NATURES[strtoupper((string) ($input['change_nature'] ?? ''))])
                ? strtoupper((string) $input['change_nature']) : 'PERMANENT',
            'site_id' => $input['site_id'] ?? null,
            'contractor_id' => $input['contractor_id'] ?? null,
            'risk_rating' => in_array(strtoupper((string) ($input['risk_rating'] ?? '')),
                self::RISK_RATINGS, true) ? strtoupper((string) $input['risk_rating']) : null,
            'impact_summary' => $input['impact_summary'] ?? null,
            'planned_date' => $input['planned_date'] ?? null,
            'status' => self::DRAFT,
            'requested_by' => $actorId,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $change = $this->findById($companyId, $id);
        $impacts = $this->refreshImpacts($companyId, $change);

        $this->audit($companyId, $actorId, 'change.registered', $id,
            ['reference' => $reference, 'category' => $category, 'impacts' => $impacts]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference, 'impacts' => $impacts];
    }

    /**
     * Re-run the detector, adding what is new and leaving alone what somebody has answered.
     *
     * Never removes and never resets. An impact a person has marked done or not applicable is a
     * decision with a reason attached, and a detector that cleared it on the next run would
     * delete somebody's work — then they would stop answering the list.
     */
    public function refreshImpacts(int $companyId, object $change): int
    {
        $existing = DB::table('sheq_change_impacts')
            ->where('company_id', $companyId)->where('change_id', $change->id)
            ->get(['impact_type', 'subject_type', 'subject_id'])
            ->map(fn ($r) => $r->impact_type.'|'.($r->subject_type ?? '').'|'.($r->subject_id ?? ''))
            ->all();

        $added = 0;

        foreach ($this->detector->detect($companyId, $change) as $impact) {
            $key = $impact['impact_type'].'|'.($impact['subject_type'] ?? '')
                .'|'.($impact['subject_id'] ?? '');

            if (in_array($key, $existing, true)) {
                continue;
            }

            DB::table('sheq_change_impacts')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'change_id' => $change->id,
                'impact_type' => $impact['impact_type'],
                'subject_type' => $impact['subject_type'],
                'subject_id' => $impact['subject_id'],
                'subject_reference' => $impact['subject_reference'],
                'subject_title' => $impact['subject_title'],
                'detected_automatically' => $impact['detected_automatically'],
                'is_mandatory' => $impact['is_mandatory'],
                'status' => 'OUTSTANDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing[] = $key;
            $added++;
        }

        return $added;
    }

    /** Add an impact the detector could not know about. */
    public function addImpact(int $companyId, string $uuid, array $input, ?int $actorId = null): void
    {
        $change = $this->find($companyId, $uuid);

        $type = strtoupper(trim((string) ($input['impact_type'] ?? '')));

        if (! isset(ChangeImpactDetector::IMPACT_TYPES[$type])) {
            throw new RuntimeException('That is not one of the kinds of impact this records.');
        }

        DB::table('sheq_change_impacts')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'change_id' => $change->id,
            'impact_type' => $type,
            'subject_title' => trim((string) ($input['subject_title'] ?? '')) ?: null,
            // Marked as a person's own, because an automatic list people trust completely is
            // dangerous and one they add to is working as intended.
            'detected_automatically' => false,
            'is_mandatory' => (bool) ($input['is_mandatory'] ?? true),
            'assigned_to' => $input['assigned_to'] ?? null,
            'due_date' => $input['due_date'] ?? null,
            'status' => 'OUTSTANDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.impact_added', (int) $change->id,
            ['impact_type' => $type]);
    }

    /**
     * Answer one impact: done, or not applicable with a reason.
     */
    public function decideImpact(int $companyId, string $impactUuid, string $status,
        ?string $note = null, ?int $actorId = null): void
    {
        $status = strtoupper(trim($status));

        if (! in_array($status, ['OUTSTANDING', 'DONE', 'NOT_APPLICABLE'], true)) {
            throw new RuntimeException('An impact is outstanding, done, or not applicable.');
        }

        /*
         * A reason is required to dismiss one, and not to complete one.
         *
         * NOT_APPLICABLE is the hole in this whole feature and the easiest to walk through under
         * deadline pressure: a mandatory control cleared with one click and no explanation looks
         * identical to one that was genuinely irrelevant. Completing an impact needs no
         * justification — the work itself is the evidence.
         */
        if ($status === 'NOT_APPLICABLE' && trim((string) $note) === '') {
            throw new RuntimeException(
                'Say why this does not apply. A mandatory control dismissed without a reason is '
                .'the easiest way for a change to be made without its consequences being handled.'
            );
        }

        $impact = DB::table('sheq_change_impacts')
            ->where('company_id', $companyId)->where('uuid', $impactUuid)
            ->first(['id', 'change_id', 'impact_type']);

        if ($impact === null) {
            throw new RuntimeException('No such impact on any change in this company.');
        }

        DB::table('sheq_change_impacts')->where('id', $impact->id)->update([
            'status' => $status,
            'note' => $note,
            'completed_at' => $status === 'OUTSTANDING' ? null : now(),
            'completed_by' => $status === 'OUTSTANDING' ? null : $actorId,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.impact_decided', (int) $impact->change_id,
            ['impact_type' => $impact->impact_type, 'status' => $status, 'note' => $note]);
    }

    /**
     * Submit for approval, routed by risk (MOC-3).
     *
     * The risk rating is the workflow subtype, so "who approves a critical change" is configured
     * once and every critical change routes there. Segregation of duties is not enforced here —
     * `ApprovalService` already refuses to let a requester approve their own request at any step,
     * whatever roles they hold, and a second copy of that rule is a second place for it to be
     * wrong.
     */
    public function submit(int $companyId, string $uuid, ?int $actorId = null): array
    {
        $change = $this->find($companyId, $uuid);

        if ($change->status !== self::DRAFT) {
            throw new RuntimeException(sprintf('%s has already been submitted.', $change->reference));
        }

        if ($change->risk_rating === null) {
            throw new RuntimeException(
                'Rate the risk before submitting. The rating decides who is authorised to '
                .'approve it, so without one there is nobody to send it to.'
            );
        }

        $request = $this->approvals->openIfConfigured($companyId, [
            'subject_type' => self::SUBJECT,
            'subject_subtype' => $change->risk_rating,
            'subject_id' => (int) $change->id,
            'subject_label' => $change->reference.' — '.$change->title,
            'site_id' => $change->site_id,
            'requested_by' => $actorId,
        ]);

        DB::table('sheq_changes')->where('id', $change->id)->update([
            'status' => self::SUBMITTED,
            'approval_request_id' => $request['id'] ?? null,
            'requested_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.submitted', (int) $change->id, [
            'risk_rating' => $change->risk_rating,
            // Said plainly in the record. A tenant with no workflow for this risk band has the
            // change advance without an approval, and that has to be visible afterwards rather
            // than looking like somebody approved it.
            'approval_opened' => $request !== null,
        ]);

        return ['approval_opened' => $request !== null,
            'status' => self::SUBMITTED, 'reference' => $change->reference];
    }

    /**
     * Why this change may not be implemented yet (MOC-4), or null.
     *
     * Returned as a sentence rather than a boolean, because the screen and the refusal both need
     * to say which thing is outstanding. "Cannot be implemented" with no reason is how somebody
     * ends up implementing it anyway and recording it later.
     */
    public function blockers(int $companyId, object $change): array
    {
        $blockers = [];

        if (in_array($change->status, [self::DRAFT, self::SUBMITTED], true)) {
            $reason = $this->approvals->blockingReason($companyId, self::SUBJECT,
                (int) $change->id, $change->risk_rating, $change->site_id);

            if ($reason !== null) {
                $blockers[] = $reason;
            } elseif ($change->status === self::DRAFT) {
                $blockers[] = 'This change has not been submitted for approval yet.';
            }
        }

        if ($change->status === self::REJECTED) {
            $blockers[] = 'This change was rejected.';
        }

        $outstanding = DB::table('sheq_change_impacts')
            ->where('company_id', $companyId)->where('change_id', $change->id)
            ->where('is_mandatory', true)->where('status', 'OUTSTANDING')
            ->get(['impact_type', 'subject_reference', 'subject_title']);

        foreach ($outstanding as $impact) {
            $blockers[] = sprintf('%s: %s is still outstanding.',
                ChangeImpactDetector::IMPACT_TYPES[$impact->impact_type]['label']
                    ?? $impact->impact_type,
                $impact->subject_reference ?: ($impact->subject_title ?: 'a required control'));
        }

        return $blockers;
    }

    /**
     * Mark the change made (MOC-4's gate).
     *
     * Refuses while anything mandatory is outstanding. This is the one method in the feature that
     * has to say no — everything else is a register, and a register of changes whose checklist is
     * advisory changes nothing about how changes are made.
     */
    public function implement(int $companyId, string $uuid, ?int $actorId = null): void
    {
        $change = $this->find($companyId, $uuid);

        if ($change->status === self::IMPLEMENTED || $change->status === self::REVIEWED) {
            throw new RuntimeException(sprintf('%s is already implemented.', $change->reference));
        }

        $blockers = $this->blockers($companyId, $change);

        if ($blockers !== []) {
            throw new RuntimeException(sprintf(
                "%s cannot be implemented yet:\n- %s",
                $change->reference, implode("\n- ", $blockers)));
        }

        $days = self::REVIEW_DAYS[$change->risk_rating ?? 'MEDIUM'] ?? 60;

        DB::table('sheq_changes')->where('id', $change->id)->update([
            'status' => self::IMPLEMENTED,
            'implemented_at' => now(),
            'implemented_by' => $actorId,
            /*
             * The review date is set here, from the risk, rather than asked for (MOC-5). A field
             * somebody fills in at the end of a long form is a field left empty, and an
             * effectiveness review with no date is the same as no review. Sooner for higher
             * risk, because that is where being wrong costs most.
             */
            'effectiveness_review_due' => Carbon::today()->addDays($days)->toDateString(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.implemented', (int) $change->id,
            ['review_due_in_days' => $days]);
    }

    /** Record the post-change review (MOC-5). */
    public function review(int $companyId, string $uuid, array $input, ?int $actorId = null): void
    {
        $change = $this->find($companyId, $uuid);

        if ($change->status !== self::IMPLEMENTED) {
            throw new RuntimeException(
                'A change can only be reviewed once it has been implemented — there is nothing '
                .'to judge the effectiveness of until then.'
            );
        }

        $result = strtoupper(trim((string) ($input['effectiveness_result'] ?? '')));

        if (! in_array($result, ['EFFECTIVE', 'PARTIALLY_EFFECTIVE', 'NOT_EFFECTIVE'], true)) {
            throw new RuntimeException('Say whether the change achieved what it was meant to.');
        }

        /*
         * A change that did not work needs an explanation, and one that did does not.
         *
         * NOT_EFFECTIVE with no notes is the review that tells nobody anything: the next person
         * reads "not effective", cannot tell whether the change was wrong or merely incomplete,
         * and the review has to be done again from memory.
         */
        if ($result !== 'EFFECTIVE' && trim((string) ($input['effectiveness_notes'] ?? '')) === '') {
            throw new RuntimeException(
                'Say what did not work. A review that records only the verdict leaves the next '
                .'person unable to tell whether the change was wrong or just unfinished.'
            );
        }

        DB::table('sheq_changes')->where('id', $change->id)->update([
            'status' => self::REVIEWED,
            'effectiveness_result' => $result,
            'effectiveness_notes' => $input['effectiveness_notes'] ?? null,
            'effectiveness_reviewed_at' => now(),
            'effectiveness_reviewed_by' => $actorId,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.reviewed', (int) $change->id,
            ['result' => $result]);
    }

    public function reject(int $companyId, string $uuid, string $reason, ?int $actorId = null): void
    {
        $change = $this->find($companyId, $uuid);

        DB::table('sheq_changes')->where('id', $change->id)->update([
            'status' => self::REJECTED,
            'rejection_reason' => $reason,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'change.rejected', (int) $change->id,
            ['reason' => $reason]);
    }

    /**
     * The register, with what is outstanding against each change.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $companyId, array $filters = [], int $limit = 300): array
    {
        $rows = DB::table('sheq_changes as c')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'c.site_id')
            ->leftJoin('sheq_contractors as con', 'con.id', '=', 'c.contractor_id')
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            ->when(! empty($filters['status']), fn ($q) => $q->where('c.status', $filters['status']))
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('c.site_id', $filters['site_id']))
            ->orderByRaw("case c.status when 'SUBMITTED' then 0 when 'APPROVED' then 1
                when 'DRAFT' then 2 when 'IMPLEMENTED' then 3 else 4 end")
            ->orderByDesc('c.id')->limit($limit)
            ->get(['c.id', 'c.uuid', 'c.reference', 'c.title', 'c.change_category',
                'c.change_nature', 'c.risk_rating', 'c.status', 'c.planned_date',
                'c.implemented_at', 'c.effectiveness_review_due', 'c.effectiveness_result',
                'c.rejection_reason', 's.name as site_name', 'con.name as contractor_name']);

        $counts = DB::table('sheq_change_impacts')
            ->where('company_id', $companyId)
            ->whereIn('change_id', $rows->pluck('id'))
            ->selectRaw('change_id, status, is_mandatory, count(*) as n')
            ->groupBy('change_id', 'status', 'is_mandatory')->get();

        return $rows->map(function ($row) use ($counts) {
            $mine = $counts->where('change_id', $row->id);

            return array_merge((array) $row, [
                'impacts_total' => (int) $mine->sum('n'),
                'impacts_outstanding' => (int) $mine->where('status', 'OUTSTANDING')->sum('n'),
                'blocking_outstanding' => (int) $mine->where('status', 'OUTSTANDING')
                    ->where('is_mandatory', true)->sum('n'),
                'review_overdue' => $row->effectiveness_review_due !== null
                    && $row->effectiveness_result === null
                    && Carbon::parse($row->effectiveness_review_due)->isPast(),
                'id' => null,
            ]);
        })->all();
    }

    /** One change, with its impacts and what is stopping it. */
    public function detail(int $companyId, string $uuid): array
    {
        $change = $this->find($companyId, $uuid);

        $impacts = DB::table('sheq_change_impacts as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.completed_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('i.company_id', $companyId)->where('i.change_id', $change->id)
            ->orderByRaw("case i.status when 'OUTSTANDING' then 0 else 1 end")
            ->orderBy('i.impact_type')
            ->get(['i.uuid', 'i.impact_type', 'i.subject_type', 'i.subject_reference',
                'i.subject_title', 'i.detected_automatically', 'i.is_mandatory', 'i.status',
                'i.due_date', 'i.completed_at', 'i.note', 'u.email',
                'p.first_name', 'p.surname'])
            ->map(fn ($i) => array_merge((array) $i, [
                'completed_by_name' => trim(($i->first_name ?? '').' '.($i->surname ?? ''))
                    ?: $i->email,
                'first_name' => null, 'surname' => null, 'email' => null,
            ]))->all();

        return [
            'change' => (array) $change,
            'impacts' => $impacts,
            'blockers' => $this->blockers($companyId, $change),
            'impact_types' => collect(ChangeImpactDetector::IMPACT_TYPES)
                ->map(fn (array $meta, string $key) => $meta + ['key' => $key])->values()->all(),
        ];
    }

    public function find(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_changes')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            throw new RuntimeException('No such change in this company.');
        }

        return $row;
    }

    private function findById(int $companyId, int $id): object
    {
        return DB::table('sheq_changes')
            ->where('company_id', $companyId)->where('id', $id)->first();
    }

    /** MOC-NNNN, per tenant. */
    private function nextReference(int $companyId): string
    {
        $last = DB::table('sheq_changes')
            ->where('company_id', $companyId)->orderByDesc('id')->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last) + 1);

        return sprintf('MOC-%04d', $next);
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SheqChange',
            'subject_id' => $subjectId,
            'after' => json_encode($after),
        ]);
    }
}
