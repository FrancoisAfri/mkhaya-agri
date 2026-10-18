<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Audit management (§4 of the SHEQ scope).
 *
 * The rules that make this an audit system rather than a checklist app:
 *
 * **A finding is graded and cited.** MAJOR_NC, MINOR_NC, OBSERVATION or OFI, against a clause. A
 * certification body accepts a finding that says "7.5.3 — records were not retrievable"; it does
 * not accept "records were untidy". The grade determines the obligation, and a system that records
 * them all as "issues" produces a report nobody can act on.
 *
 * **A finding cannot be closed by the person who fixed it.** Closure goes through the approvals
 * engine, which refuses self-approval. In a surveillance audit the single most common adverse
 * observation is an organisation closing its own non-conformances without independent verification,
 * and it is trivially avoidable.
 *
 * **An audit with open major non-conformances cannot be closed.** A major NC is, by definition, a
 * failure of the management system. Closing the audit around it would leave a report saying the
 * system conforms while the reason it does not is still open in the same database.
 */
class AuditService
{
    public const GRADES = ['MAJOR_NC', 'MINOR_NC', 'OBSERVATION', 'OFI'];

    /** Grades that carry a duty to respond. An OFI is a suggestion; an NC is not. */
    public const ACTIONABLE_GRADES = ['MAJOR_NC', 'MINOR_NC'];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ActionService $actions,
    ) {}

    /** Plan an audit. */
    public function plan(int $companyId, array $data, ?int $actorId = null): array
    {
        if (trim($data['title'] ?? '') === '') {
            throw new InvalidArgumentException('An audit needs a title describing what is audited.');
        }

        $uuid = (string) Str::uuid();
        $reference = $this->nextReference($companyId);

        $id = DB::table('sheq_audits')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'reference' => $reference,
            'programme_id' => $data['programme_id'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'audit_type' => $data['audit_type'] ?? 'INTERNAL',
            'standard' => $data['standard'] ?? null,
            'title' => $data['title'],
            'scope' => $data['scope'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'auditee_type' => $data['auditee_type'] ?? null,
            'auditee_id' => $data['auditee_id'] ?? null,
            'lead_auditor_id' => $data['lead_auditor_id'] ?? $actorId,
            'audit_team' => json_encode($data['audit_team'] ?? []),
            'planned_start' => $data['planned_start'] ?? null,
            'planned_end' => $data['planned_end'] ?? null,
            'status' => 'PLANNED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference, 'status' => 'PLANNED'];
    }

    /**
     * Record the fieldwork.
     *
     * Scoring counts only the questions that were actually answered. Marking a question "not
     * applicable" must not drag the score down — an audit of a site with no confined spaces should
     * not score badly for having no confined-space procedure.
     *
     * @param  array<string,array{answer:string, note?:string, evidence?:string}>  $responses
     */
    public function recordFieldwork(int $companyId, string $uuid, array $responses, ?int $actorId = null): array
    {
        $audit = $this->find($companyId, $uuid);

        if (in_array($audit->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new RuntimeException("Audit {$audit->reference} is {$audit->status} and cannot be edited.");
        }

        $scored = 0;
        $earned = 0;

        foreach ($responses as $response) {
            $answer = strtoupper($response['answer'] ?? '');

            if ($answer === 'NA' || $answer === 'NOT_APPLICABLE') {
                continue;
            }

            $scored++;
            $earned += match ($answer) {
                'CONFORMS', 'YES' => 1,
                'PARTIAL' => 0.5,
                default => 0,
            };
        }

        $score = $scored === 0 ? null : round(($earned / $scored) * 100, 2);

        DB::table('sheq_audits')->where('id', $audit->id)->update([
            'responses' => json_encode($responses),
            'score' => $score,
            'max_score' => 100,
            'started_at' => $audit->started_at ?? now(),
            'status' => 'IN_PROGRESS',
            'updated_at' => now(),
        ]);

        return ['score' => $score, 'questions_scored' => $scored];
    }

    /**
     * Raise a finding, and — where it carries a duty — a corrective action to answer it.
     *
     * The action is created here rather than left to the auditee because a non-conformance with no
     * action is the failure mode this whole register exists to prevent: it is agreed in the closing
     * meeting, written in the report, and then nothing happens because nobody owned it.
     */
    public function raiseFinding(int $companyId, string $auditUuid, array $data, ?int $actorId = null): array
    {
        $audit = $this->find($companyId, $auditUuid);

        $grade = strtoupper($data['grade'] ?? '');

        if (! in_array($grade, self::GRADES, true)) {
            throw new InvalidArgumentException(sprintf(
                "'%s' is not a finding grade. Use one of: %s. The grade decides what the auditee "
                .'must do about it, so it cannot be left blank.', $grade, implode(', ', self::GRADES),
            ));
        }

        if (trim($data['statement'] ?? '') === '') {
            throw new InvalidArgumentException('A finding needs a statement of what was found.');
        }

        if (in_array($grade, self::ACTIONABLE_GRADES, true) && trim($data['clause'] ?? '') === '') {
            throw new InvalidArgumentException(
                'A non-conformance must cite the clause or requirement it breaches. Without one it '
                .'is an opinion, and a certification body will reject it.'
            );
        }

        return DB::transaction(function () use ($companyId, $audit, $grade, $data, $actorId) {
            $uuid = (string) Str::uuid();
            $sequence = DB::table('sheq_audit_findings')->where('audit_id', $audit->id)->count() + 1;
            $reference = $audit->reference.'-F'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

            $actionId = null;

            if (in_array($grade, self::ACTIONABLE_GRADES, true)) {
                $action = $this->actions->create($companyId, [
                    'title' => Str::limit("{$grade}: ".$data['statement'], 160),
                    'description' => ($data['statement'] ?? '')
                        ."\n\nClause: ".($data['clause'] ?? 'n/a')
                        ."\nAudit: {$audit->reference}",
                    'source_type' => 'AUDIT_FINDING',
                    'source_id' => 0,
                    'priority' => $grade === 'MAJOR_NC' ? 'HIGH' : 'MEDIUM',
                    'assigned_to' => $data['responsible_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                    'site_id' => $audit->site_id,
                ], $actorId);

                $actionId = $action['id'] ?? null;
            }

            $id = DB::table('sheq_audit_findings')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'audit_id' => $audit->id,
                'reference' => $reference,
                'grade' => $grade,
                'clause' => $data['clause'] ?? null,
                'question_id' => $data['question_id'] ?? null,
                'statement' => $data['statement'],
                'evidence' => $data['evidence'] ?? null,
                'responsible_id' => $data['responsible_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'action_id' => $actionId,
                'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($actionId !== null) {
                DB::table('sheq_actions')->where('id', $actionId)->update(['source_id' => $id]);
            }

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference,
                'grade' => $grade, 'action_id' => $actionId];
        });
    }

    /** The auditee's response: root cause and what they did about it. */
    public function respondToFinding(int $companyId, string $uuid, array $data, ?int $actorId = null): void
    {
        $finding = $this->findFinding($companyId, $uuid);

        if (trim($data['root_cause'] ?? '') === '') {
            throw new InvalidArgumentException(
                'A response needs a root cause. "We reminded the team" is a correction, not a cause '
                .'— and a corrective action built on it will not prevent recurrence.'
            );
        }

        DB::table('sheq_audit_findings')->where('id', $finding->id)->update([
            'root_cause' => $data['root_cause'],
            'correction' => $data['correction'] ?? null,
            'status' => 'RESPONSE_SUBMITTED',
            'updated_at' => now(),
        ]);
    }

    /**
     * Ask for the finding to be verified and closed.
     *
     * Routed through the approvals engine, which refuses to let the requester approve. That is the
     * whole control: the person who fixed it cannot be the person who agrees it is fixed.
     */
    public function requestClosure(int $companyId, string $uuid, int $actorId): array
    {
        $finding = $this->findFinding($companyId, $uuid);

        if ($finding->status === 'CLOSED') {
            throw new RuntimeException("Finding {$finding->reference} is already closed.");
        }

        if ($finding->root_cause === null) {
            throw new RuntimeException(
                "Finding {$finding->reference} has no root cause recorded, so there is nothing to "
                .'verify. Submit a response first.'
            );
        }

        return $this->approvals->open($companyId, [
            'subject_type' => 'AUDIT_FINDING',
            'subject_id' => (int) $finding->id,
            'subject_label' => $finding->reference.' — '.Str::limit($finding->statement, 80),
            'subject_subtype' => $finding->grade,
            'requested_by' => $actorId,
            'note' => 'Verification that the corrective action is effective.',
        ]);
    }

    /** Apply the verification outcome once the approval completes. */
    public function applyClosureDecision(int $companyId, int $findingId, bool $approved,
        int $verifierId, ?string $note = null): void
    {
        DB::table('sheq_audit_findings')->where('company_id', $companyId)->where('id', $findingId)
            ->update([
                'status' => $approved ? 'CLOSED' : 'REJECTED',
                'verified_at' => now(),
                'verified_by' => $verifierId,
                'verification_note' => $note,
                'updated_at' => now(),
            ]);
    }

    /**
     * Close the audit.
     *
     * Refused while a major non-conformance is open. A major NC is a failure of the management
     * system itself; closing the audit around it would produce a report asserting conformity while
     * the reason it does not conform sits open in the same table.
     */
    public function close(int $companyId, string $uuid, int $actorId, ?string $summary = null): void
    {
        $audit = $this->find($companyId, $uuid);

        $openMajors = DB::table('sheq_audit_findings')
            ->where('company_id', $companyId)->where('audit_id', $audit->id)
            ->where('grade', 'MAJOR_NC')->whereNotIn('status', ['CLOSED'])
            ->whereNull('deleted_at')->count();

        if ($openMajors > 0) {
            throw new RuntimeException(sprintf(
                'Audit %s has %d open major non-conformance(s). A major NC is a failure of the '
                .'management system, and closing the audit while one stands would produce a report '
                .'asserting conformity that the findings contradict.',
                $audit->reference, $openMajors,
            ));
        }

        DB::table('sheq_audits')->where('id', $audit->id)->update([
            'status' => 'CLOSED',
            'completed_at' => now(),
            'summary' => $summary,
            'passed' => $this->passed($companyId, $audit->id),
            'updated_at' => now(),
        ]);
    }

    /** Passing means no non-conformances outstanding, whatever the percentage says. */
    private function passed(int $companyId, int $auditId): bool
    {
        return DB::table('sheq_audit_findings')
            ->where('company_id', $companyId)->where('audit_id', $auditId)
            ->whereIn('grade', self::ACTIONABLE_GRADES)
            ->whereNotIn('status', ['CLOSED'])->whereNull('deleted_at')
            ->doesntExist();
    }

    /** @return array<string,mixed> */
    public function summary(int $companyId, string $uuid): array
    {
        $audit = $this->find($companyId, $uuid);

        $findings = DB::table('sheq_audit_findings')
            ->where('company_id', $companyId)->where('audit_id', $audit->id)
            ->whereNull('deleted_at')->orderBy('grade')->orderBy('reference')
            ->get(['uuid', 'reference', 'grade', 'clause', 'statement', 'root_cause',
                'status', 'due_date', 'action_id']);

        return [
            'audit' => (array) $audit,
            'findings' => $findings->map(fn ($f) => (array) $f)->values()->all(),
            'counts' => [
                'major' => $findings->where('grade', 'MAJOR_NC')->count(),
                'minor' => $findings->where('grade', 'MINOR_NC')->count(),
                'observation' => $findings->where('grade', 'OBSERVATION')->count(),
                'ofi' => $findings->where('grade', 'OFI')->count(),
                'open' => $findings->whereNotIn('status', ['CLOSED'])->count(),
            ],
        ];
    }

    private function find(int $companyId, string $uuid): object
    {
        $audit = DB::table('sheq_audits')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($audit === null) {
            throw new RuntimeException("No audit {$uuid} in this company.");
        }

        return $audit;
    }

    private function findFinding(int $companyId, string $uuid): object
    {
        $finding = DB::table('sheq_audit_findings')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($finding === null) {
            throw new RuntimeException("No audit finding {$uuid} in this company.");
        }

        return $finding;
    }

    private function nextReference(int $companyId): string
    {
        $year = now()->year;
        $prefix = "AUD-{$year}-";

        $last = DB::table('sheq_audits')->where('company_id', $companyId)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')->value('reference');

        $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
