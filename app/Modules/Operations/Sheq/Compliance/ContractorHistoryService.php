<?php

namespace App\Modules\Operations\Sheq\Compliance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The whole story of one contractor, in one place and in order (CON-22, COM-5).
 *
 * Every piece of this existed already, and that was the problem: it existed in six places. The
 * audit log held approvals, blocks and document changes. Safety file transitions lived in their
 * own table. Access decisions were in the access-event register. Site decisions were in a third
 * place, scores in a fourth. So the question a host is actually asked — by an auditor, by a
 * client, by an attorney after somebody is hurt — could only be answered by somebody who knew
 * all six and was willing to read them side by side with a pen.
 *
 * Two questions, one ledger:
 *
 * **CON-22, "what has happened to this contractor".** Everything, oldest or newest first,
 * immutable. The audit log is already append-only and hash-chained at the database level —
 * triggers reject UPDATE, DELETE and TRUNCATE, and each row carries the previous row's hash — so
 * "immutable" here is a property of the storage rather than a promise made by this class.
 *
 * **COM-5, "why was this person blocked".** The same events, narrowed to the ones that changed
 * the answer, with the evidence and the timestamp that did it. The distinction matters: a
 * contractor's history runs to hundreds of rows, and a manager asking why a gate closed this
 * morning needs the four that caused it, not all of them.
 *
 * Nothing here writes. A history that can be edited by the screen that displays it is not a
 * history.
 */
class ContractorHistoryService
{
    /**
     * The event kinds CON-22 requires, and where each is actually read from.
     *
     * Declared so `ContractorHistoryTest` can assert the requirement's list is covered. A history
     * screen silently missing one kind is the worst possible failure here: it looks complete, and
     * it is produced in answer to a formal request.
     *
     * @var array<string,string>
     */
    public const SOURCES = [
        'APPROVAL' => 'sheq_audit_log',
        'REJECTION' => 'sheq_safety_file_transitions',
        'BLOCK' => 'sheq_audit_log',
        'UNBLOCK' => 'sheq_audit_log',
        'DOCUMENT' => 'sheq_contractor_documents',
        'SAFETY_FILE' => 'sheq_safety_file_submissions',
        'ACCESS_DECISION' => 'sheq_access_events',
        'SCORE' => 'sheq_contractor_scores',
        'INCIDENT' => 'sheq_incidents',
        'AUDIT' => 'sheq_audits',
    ];

    /**
     * Audit-log actions that belong to a contractor's story, and how to read each one.
     *
     * A whitelist rather than everything mentioning the contractor, because the log also carries
     * configuration changes and reads, and a history that includes "somebody opened this page" is
     * a history nobody finishes.
     *
     * @var array<string,string>
     */
    private const AUDITED = [
        'contractor.registered' => 'APPROVAL',
        'contractor.access_status_changed' => 'BLOCK',
        'contractor.site_decision' => 'APPROVAL',
        'contractor.site_decision_withdrawn' => 'BLOCK',
        'contractor.document_attached' => 'DOCUMENT',
        'contractor.document_superseded' => 'DOCUMENT',
        'contractor.document_decided' => 'DOCUMENT',
        'contractor.worker_registered' => 'APPROVAL',
        'contractor.supplier_linked' => 'APPROVAL',
        'contractor.score_changed' => 'SCORE',
        'access_override.granted' => 'APPROVAL',
        'access_override.revoked' => 'BLOCK',
        'access_override.expired' => 'BLOCK',
        'access_pass.issued' => 'APPROVAL',
        'access_pass.rotated' => 'APPROVAL',
        'safety_file.submitted' => 'SAFETY_FILE',
        'safety_file.expired' => 'SAFETY_FILE',
        'safety_file.exported' => 'SAFETY_FILE',
    ];

    /**
     * Everything, newest first.
     *
     * @return array<string,mixed>
     */
    public function forContractor(int $companyId, int $contractorId, array $options = []): array
    {
        $from = isset($options['from']) ? Carbon::parse($options['from']) : null;
        $kinds = $options['kinds'] ?? null;

        $events = array_merge(
            $this->auditEvents($companyId, $contractorId),
            $this->safetyFileEvents($companyId, $contractorId),
            $this->documentEvents($companyId, $contractorId),
            $this->accessEvents($companyId, $contractorId),
            $this->scoreEvents($companyId, $contractorId),
            $this->incidentEvents($companyId, $contractorId),
            $this->auditFindingEvents($companyId, $contractorId),
        );

        if ($from !== null) {
            $events = array_values(array_filter($events,
                fn (array $e) => Carbon::parse($e['at'])->gte($from)));
        }

        if (is_array($kinds) && $kinds !== []) {
            $events = array_values(array_filter($events,
                fn (array $e) => in_array($e['kind'], $kinds, true)));
        }

        usort($events, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return [
            'events' => $events,
            'counts' => $this->countByKind($events),
            // Named so a screen can offer the filter without inventing the vocabulary.
            'kinds' => array_keys(self::SOURCES),
        ];
    }

    /**
     * Why is this contractor, or this person, in the state they are in (COM-5)?
     *
     * Runs the live decision first, then finds the event that caused each refusal. The decision
     * says "COID expired"; the history says it expired on the 3rd, that the replacement was
     * rejected on the 11th, and who rejected it. That second half is what somebody can act on,
     * and it is the half that used to require reading four registers.
     *
     * @return array<string,mixed>
     */
    public function why(int $companyId, int $contractorId, ?int $workerId = null,
        ?int $siteId = null, ?string $activity = null): array
    {
        $engine = app(ComplianceDecisionService::class);

        $decision = $workerId === null
            ? $engine->forContractor($companyId, $contractorId, $siteId)
            : $engine->forWorker($companyId, $workerId, $siteId, $activity);

        $history = $this->forContractor($companyId, $contractorId)['events'];

        $timeline = [];

        foreach ($decision['findings'] as $finding) {
            $timeline[] = [
                'code' => $finding['code'],
                'severity' => $finding['severity'] ?? 'BLOCKING',
                'detail' => $finding['detail'] ?? '',
                'check' => $finding['check'] ?? null,
                /*
                 * The event that caused it, where one can be identified. Not guessed: a finding
                 * with no traceable event says so, because "we cannot show you why" is honest and
                 * an invented cause is not.
                 */
                'caused_by' => $this->causeOf($finding, $history),
            ];
        }

        /*
         * Blocking first. Somebody asking why a gate closed does not want to read nine advisories
         * before reaching the one that closed it.
         */
        usort($timeline, fn (array $a, array $b) => ($a['severity'] === 'BLOCKING' ? 0 : 1)
            <=> ($b['severity'] === 'BLOCKING' ? 0 : 1));

        return [
            'outcome' => $decision['outcome'],
            'allowed' => $decision['allowed'],
            'subject' => $decision['subject'],
            'question' => $decision['question'],
            'timeline' => $timeline,
            'unverified' => $decision['unverified'],
        ];
    }

    /**
     * The event behind a finding.
     *
     * Matched on the subject of the reason code rather than on the words of the detail, because
     * the detail is a sentence composed for a person and matching on it would break the first
     * time somebody improved the wording.
     *
     * @param  list<array<string,mixed>>  $history
     * @return array<string,mixed>|null
     */
    private function causeOf(array $finding, array $history): ?array
    {
        $kind = match (ReasonCode::subjectOf((string) $finding['code'])) {
            'DOCUMENT' => 'DOCUMENT',
            'SAFETY_FILE' => 'SAFETY_FILE',
            'SITE' => 'BLOCK',
            'CONTRACTOR' => 'BLOCK',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        foreach ($history as $event) {
            if ($event['kind'] === $kind || ($kind === 'BLOCK' && $event['kind'] === 'APPROVAL')) {
                return ['at' => $event['at'], 'title' => $event['title'],
                    'actor' => $event['actor'], 'kind' => $event['kind']];
            }
        }

        return null;
    }

    // ── The sources ───────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function auditEvents(int $companyId, int $contractorId): array
    {
        $rows = DB::table('sheq_audit_log')
            ->where('company_id', $companyId)
            ->whereIn('action', array_keys(self::AUDITED))
            ->where(function ($q) use ($contractorId) {
                /*
                 * Matched on the subject id where the subject IS the contractor, and on the
                 * recorded contractor id otherwise. Worker and document events name their own
                 * subject, so a plain `subject_id = contractor` would lose them.
                 */
                $q->where(fn ($w) => $w->whereIn('subject_type', ['Contractor', 'SheqContractor'])
                    ->where('subject_id', $contractorId))
                    ->orWhereRaw("(after->>'contractor_id') = ?", [(string) $contractorId])
                    ->orWhereRaw("(before->>'contractor_id') = ?", [(string) $contractorId]);
            })
            ->orderByDesc('occurred_at')->limit(500)
            ->get(['action', 'occurred_at', 'actor_id', 'reason', 'before', 'after', 'hash']);

        return $rows->map(fn ($row) => [
            'kind' => self::AUDITED[$row->action] ?? 'APPROVAL',
            'at' => (string) $row->occurred_at,
            'title' => $this->titleFor($row->action),
            'detail' => $row->reason ?: $this->changeSummary($row->before, $row->after),
            'actor' => $this->actorName($companyId, $row->actor_id),
            'source' => 'sheq_audit_log',
            // Carried so a screen can show that this row is part of a sealed chain. An audit
            // trail nobody can verify is a claim rather than evidence.
            'sealed' => $row->hash !== null,
        ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function safetyFileEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_safety_file_transitions as t')
            ->join('sheq_safety_file_submissions as s', 's.id', '=', 't.submission_id')
            ->where('t.company_id', $companyId)->where('s.contractor_id', $contractorId)
            ->orderByDesc('t.occurred_at')->limit(300)
            ->get(['t.from_state', 't.to_state', 't.occurred_at', 't.actor_id', 't.reason',
                't.comment', 's.version'])
            ->map(fn ($row) => [
                // A refusal is its own kind, not a safety-file state change among others. It is
                // the event a contractor disputes and the one an auditor asks about first.
                'kind' => in_array($row->to_state, ['CHANGES_REQUIRED', 'BLOCKED', 'SUSPENDED'], true)
                    ? 'REJECTION' : 'SAFETY_FILE',
                'at' => (string) $row->occurred_at,
                'title' => sprintf('Safety file v%d: %s', $row->version,
                    strtolower(str_replace('_', ' ', (string) $row->to_state))),
                'detail' => trim(($row->reason ?: '').' '.($row->comment ?: '')) ?: sprintf(
                    'From %s.', strtolower(str_replace('_', ' ', (string) $row->from_state))),
                'actor' => $this->actorName($companyId, $row->actor_id),
                'source' => 'sheq_safety_file_transitions',
                'sealed' => false,
            ])->all();
    }

    /**
     * Document versions.
     *
     * Read from the documents themselves rather than from the audit log, because a version chain
     * is the fact CON-24 cares about: which certificate replaced which, and when the old one
     * stopped counting.
     *
     * @return list<array<string,mixed>>
     */
    private function documentEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->orderByDesc('created_at')->limit(300)
            ->get(['document_type', 'title', 'version', 'created_at', 'superseded_at',
                'supersede_reason', 'status', 'expires_on'])
            ->flatMap(function ($row) {
                $events = [[
                    'kind' => 'DOCUMENT',
                    'at' => (string) $row->created_at,
                    'title' => sprintf('%s supplied%s', $row->title ?: $row->document_type,
                        $row->version > 1 ? ' (v'.$row->version.')' : ''),
                    'detail' => $row->expires_on === null
                        ? 'No expiry recorded.'
                        : 'Expires '.$row->expires_on.'.',
                    'actor' => null,
                    'source' => 'sheq_contractor_documents',
                    'sealed' => false,
                ]];

                if ($row->superseded_at !== null) {
                    $events[] = [
                        'kind' => 'DOCUMENT',
                        'at' => (string) $row->superseded_at,
                        'title' => sprintf('%s replaced', $row->title ?: $row->document_type),
                        'detail' => (string) ($row->supersede_reason ?: ''),
                        'actor' => null,
                        'source' => 'sheq_contractor_documents',
                        'sealed' => false,
                    ];
                }

                return $events;
            })->all();
    }

    /**
     * Access decisions.
     *
     * Refusals only, and that is a judgement rather than an oversight: a contractor with eighty
     * people on site produces thousands of ordinary entries a month, and mixing them in would
     * bury every other kind of event. The attendance register is where the full movement history
     * lives and is linked from the screen.
     *
     * @return list<array<string,mixed>>
     */
    private function accessEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_access_events')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('allowed', false)
            ->orderByDesc('occurred_at')->limit(200)
            ->get(['person_name', 'occurred_at', 'reasons', 'method', 'was_monitor_only',
                'direction'])
            ->map(fn ($row) => [
                'kind' => 'ACCESS_DECISION',
                'at' => (string) $row->occurred_at,
                'title' => sprintf('%s refused at the gate', $row->person_name ?: 'Somebody'),
                'detail' => implode(' ', json_decode((string) $row->reasons, true) ?: [])
                    .($row->was_monitor_only ? ' (monitor mode — the gate still opened.)' : ''),
                'actor' => null,
                'source' => 'sheq_access_events',
                'sealed' => false,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function scoreEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_scores')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNotNull('score')
            ->orderByDesc('period')->limit(36)
            ->get(['period', 'score', 'rating', 'computed_at'])
            ->map(fn ($row) => [
                'kind' => 'SCORE',
                'at' => (string) ($row->computed_at ?: $row->period.'-01 00:00:00'),
                'title' => sprintf('Score for %s: %s', $row->period, $row->score),
                'detail' => 'Rated '.strtolower((string) $row->rating).'.',
                'actor' => null,
                'source' => 'sheq_contractor_scores',
                'sealed' => false,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function incidentEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->orderByDesc('occurred_at')->limit(200)
            ->get(['reference', 'incident_type', 'occurred_at', 'actual_severity',
                'potential_severity', 'status'])
            ->map(fn ($row) => [
                'kind' => 'INCIDENT',
                'at' => (string) $row->occurred_at,
                'title' => sprintf('%s — %s', $row->reference,
                    strtolower(str_replace('_', ' ', (string) $row->incident_type))),
                'detail' => sprintf('Severity %s, potential %s. %s',
                    $row->actual_severity ?? '-', $row->potential_severity ?? '-',
                    ucfirst(strtolower(str_replace('_', ' ', (string) $row->status)))),
                'actor' => null,
                'source' => 'sheq_incidents',
                'sealed' => false,
            ])->all();
    }

    /**
     * Audits and their findings against this contractor.
     *
     * @return list<array<string,mixed>>
     */
    private function auditFindingEvents(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_audits')
            ->where('company_id', $companyId)
            // `auditee_*`, not `subject_*`: an audit names who was audited, and the two
            // vocabularies sit side by side in this schema.
            ->where('auditee_type', 'CONTRACTOR')->where('auditee_id', $contractorId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(100)
            ->get(['reference', 'title', 'status', 'created_at', 'completed_at'])
            ->map(fn ($row) => [
                'kind' => 'AUDIT',
                'at' => (string) ($row->completed_at ?: $row->created_at),
                'title' => sprintf('Audit %s: %s', $row->reference, $row->title),
                'detail' => ucfirst(strtolower(str_replace('_', ' ', (string) $row->status))),
                'actor' => null,
                'source' => 'sheq_audits',
                'sealed' => false,
            ])->all();
    }

    // ── Presentation helpers ──────────────────────────────────────────────────────────────────

    /** @param  list<array<string,mixed>>  $events */
    private function countByKind(array $events): array
    {
        $counts = array_fill_keys(array_keys(self::SOURCES), 0);

        foreach ($events as $event) {
            $counts[$event['kind']] = ($counts[$event['kind']] ?? 0) + 1;
        }

        return $counts;
    }

    private function titleFor(string $action): string
    {
        return ucfirst(strtolower(str_replace(['.', '_'], ' ', $action)));
    }

    /** What changed, where the log recorded a before and an after. */
    private function changeSummary(?string $before, ?string $after): string
    {
        $from = json_decode((string) $before, true);
        $to = json_decode((string) $after, true);

        if (! is_array($to)) {
            return '';
        }

        foreach (['status', 'access_status', 'state', 'outcome'] as $field) {
            if (isset($to[$field])) {
                return isset($from[$field])
                    ? sprintf('%s → %s', $from[$field], $to[$field])
                    : (string) $to[$field];
            }
        }

        return '';
    }

    private function actorName(int $companyId, ?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        /*
         * Named from `hr_people`, because there is no `users.name` column in this product and a
         * history that says "user 412" answers nothing an auditor asked.
         */
        $person = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $actorId)
            ->first(['u.email', 'p.first_name', 'p.surname']);

        if ($person === null) {
            return null;
        }

        $name = trim(($person->first_name ?? '').' '.($person->surname ?? ''));

        return $name !== '' ? $name : $person->email;
    }
}
