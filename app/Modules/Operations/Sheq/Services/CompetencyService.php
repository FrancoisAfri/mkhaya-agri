<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Competency governance (§5.6).
 *
 * SHEQ is the governance layer over LMS's engine: it holds what people must have, works out who
 * is short, and derives status. Two rules do most of the work.
 *
 * **Precedence** (SHEQ-COMP-2): individual > task > role > department > site. A named exemption for
 * one person must beat a blanket site requirement, or the register cannot represent reality — the
 * new starter who is not yet cleared for confined space entry, the contractor exempt from an
 * internal induction.
 *
 * **Expiry drives everything** (SHEQ-COMP-5). Status is derived from dates rather than stored as a
 * fact someone remembered to update, so a certificate that lapsed overnight is EXPIRED the next
 * morning without a job having run.
 */
class CompetencyService
{
    /** Notification gates in days before expiry (SHEQ-COMP-5). */
    public const NOTIFY_AT_DAYS = [90, 60, 30, 14, 7, 0];

    /** Days before expiry at which status becomes EXPIRING. */
    private const EXPIRING_WINDOW = 90;

    /** Most specific first — the order is the precedence rule (SHEQ-COMP-2). */
    private const PRECEDENCE = ['EMPLOYEE', 'TASK', 'ROLE', 'DEPARTMENT', 'SITE', 'CONTRACTOR_TYPE'];

    /**
     * The competencies a person must hold, given their context.
     *
     * @param  array<string,string|null>  $context  e.g. ['ROLE' => 'Electrician', 'SITE' => '3']
     * @return list<array<string,mixed>>
     */
    public function requiredFor(int $companyId, int $hrPersonId, array $context): array
    {
        $context['EMPLOYEE'] = (string) $hrPersonId;

        $rows = DB::table('sheq_competency_requirements as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.company_id', $companyId)
            ->where('c.is_active', true)
            ->whereNull('c.deleted_at')
            ->where(function ($query) use ($context) {
                foreach ($context as $type => $id) {
                    if ($id === null) {
                        continue;
                    }
                    $query->orWhere(fn ($q) => $q->where('r.applies_to_type', $type)
                        ->where('r.applies_to_id', (string) $id));
                }
            })
            ->where(fn ($q) => $q->whereNull('r.effective_from')->orWhere('r.effective_from', '<=', now()))
            ->get([
                'c.id as competency_id', 'c.code', 'c.title', 'c.competency_type', 'c.validity_months',
                'c.blocks_work_when_expired', 'c.regulatory_reference',
                'r.applies_to_type', 'r.grace_days', 'r.is_exempt',
            ]);

        // Resolve precedence: for each competency keep only the most specific rule that matched.
        $winners = [];
        foreach ($rows as $row) {
            $rank = array_search($row->applies_to_type, self::PRECEDENCE, true);
            $rank = $rank === false ? PHP_INT_MAX : $rank;

            $existing = $winners[$row->competency_id] ?? null;
            if ($existing === null || $rank < $existing['rank']) {
                $winners[$row->competency_id] = ['rank' => $rank, 'row' => $row];
            }
        }

        $required = [];
        foreach ($winners as $winner) {
            $row = $winner['row'];

            // An exemption at the winning level removes the requirement entirely — that is the
            // whole point of precedence, and treating it as "still required but excused" would put
            // the person permanently on the non-compliance list.
            if ($row->is_exempt) {
                continue;
            }

            $required[] = [
                'competency_id' => $row->competency_id,
                'code' => $row->code,
                'title' => $row->title,
                'type' => $row->competency_type,
                'validity_months' => $row->validity_months,
                'blocks_work' => (bool) $row->blocks_work_when_expired,
                'regulatory_reference' => $row->regulatory_reference,
                'required_by' => $row->applies_to_type,
                'grace_days' => (int) $row->grace_days,
            ];
        }

        return $required;
    }

    /**
     * Requirements the person does not currently satisfy (SHEQ-COMP-3 emits one event per gap).
     *
     * @return list<array<string,mixed>>
     */
    public function gapsFor(int $companyId, int $hrPersonId, array $context): array
    {
        $records = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('hr_person_id', $hrPersonId)
            ->whereNull('deleted_at')->get()->keyBy('competency_id');

        $gaps = [];

        foreach ($this->requiredFor($companyId, $hrPersonId, $context) as $requirement) {
            $record = $records[$requirement['competency_id']] ?? null;
            $status = $record ? $this->deriveStatus($record) : 'NOT_STARTED';

            if (in_array($status, ['COMPLIANT', 'EXPIRING', 'EXEMPT', 'IN_PROGRESS'], true)) {
                continue;
            }

            $gaps[] = $requirement + [
                'status' => $status,
                'expires_at' => $record->expires_at ?? null,
            ];
        }

        return $gaps;
    }

    /**
     * Status derived from dates, plus grace where the requirement allows it.
     *
     * A revoked appointment is EXPIRED whatever its date says: revocation is the point.
     */
    public function deriveStatus(object $record, int $graceDays = 0): string
    {
        if ($record->revoked_at !== null) {
            return 'EXPIRED';
        }

        if (in_array($record->status, ['EXEMPT', 'IN_PROGRESS', 'FAILED'], true)) {
            return $record->status;
        }

        if ($record->achieved_at === null) {
            return 'NOT_STARTED';
        }

        // An appointment that was never accepted is not in force — a letter nobody signed is paper.
        if ($record->appointment_scope !== null && $record->accepted_at === null) {
            return 'NOT_STARTED';
        }

        if ($record->expires_at === null) {
            return 'COMPLIANT';
        }

        $expiry = Carbon::parse($record->expires_at)->addDays($graceDays);
        $daysLeft = Carbon::today()->diffInDays($expiry, false);

        return match (true) {
            $daysLeft < 0 => 'EXPIRED',
            $daysLeft <= self::EXPIRING_WINDOW => 'EXPIRING',
            default => 'COMPLIANT',
        };
    }

    /**
     * Capture a competency held outside LMS — a forklift licence, a PDP, first aid from an external
     * provider (SHEQ-COMP-7). Same expiry machinery as an LMS-issued one.
     */
    public function recordManual(int $companyId, int $hrPersonId, string $competencyCode, array $input, ?int $actorId = null): int
    {
        $competency = $this->findCompetency($companyId, $competencyCode);

        $achievedAt = isset($input['achieved_at']) ? Carbon::parse($input['achieved_at']) : Carbon::today();
        $expiresAt = $input['expires_at'] ?? ($competency->validity_months
            ? $achievedAt->copy()->addMonths((int) $competency->validity_months)->toDateString()
            : null);

        $id = DB::table('sheq_competency_records')->updateOrInsert(
            ['company_id' => $companyId, 'hr_person_id' => $hrPersonId, 'competency_id' => $competency->id],
            [
                'uuid' => (string) Str::uuid(),
                'status' => 'COMPLIANT',
                'achieved_at' => $achievedAt->toDateString(),
                'expires_at' => $expiresAt,
                'score' => $input['score'] ?? null,
                'certificate_number' => $input['certificate_number'] ?? null,
                'issuing_body' => $input['issuing_body'] ?? null,
                'evidence_path' => $input['evidence_path'] ?? null,
                'source' => $input['source'] ?? 'MANUAL',
                'last_synced_at' => now(),
                'revoked_at' => null,
                'revocation_reason' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $recordId = (int) DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('hr_person_id', $hrPersonId)
            ->where('competency_id', $competency->id)->value('id');

        $this->audit($companyId, $actorId, 'competency.recorded', $recordId, null, [
            'competency' => $competency->code, 'hr_person_id' => $hrPersonId,
            'expires_at' => $expiresAt, 'source' => $input['source'] ?? 'MANUAL',
        ]);

        return $recordId;
    }

    /**
     * Make a legal appointment (SHEQ-COMP-8) — OHSA 16(2), 17, GSR, MHSA 2.9.2, Construction 8.1.
     *
     * An appointment needs a scope and a signed letter, and does not take effect until the
     * appointee accepts it. That is not ceremony: an appointment the person never agreed to is
     * unenforceable, and it is exactly what an inspector asks to see.
     */
    public function appoint(int $companyId, int $hrPersonId, string $competencyCode, array $input, ?int $actorId = null): int
    {
        $competency = $this->findCompetency($companyId, $competencyCode);

        if ($competency->competency_type !== 'APPOINTMENT') {
            throw new RuntimeException(
                "Competency {$competency->code} is not an appointment. Use recordManual() for training and licences."
            );
        }

        if (empty($input['scope'])) {
            throw new RuntimeException('An appointment needs a scope — what the appointee is responsible for.');
        }

        if (empty($input['document_id']) && empty($input['evidence_path'])) {
            throw new RuntimeException('An appointment needs its signed letter attached (SHEQ-COMP-8).');
        }

        $effectiveFrom = isset($input['effective_from'])
            ? Carbon::parse($input['effective_from']) : Carbon::today();

        DB::table('sheq_competency_records')->updateOrInsert(
            ['company_id' => $companyId, 'hr_person_id' => $hrPersonId, 'competency_id' => $competency->id],
            [
                'uuid' => (string) Str::uuid(),
                'status' => 'NOT_STARTED',   // becomes compliant on acceptance
                'achieved_at' => $effectiveFrom->toDateString(),
                'expires_at' => $input['expires_at'] ?? ($competency->validity_months
                    ? $effectiveFrom->copy()->addMonths((int) $competency->validity_months)->toDateString()
                    : null),
                'appointment_scope' => $input['scope'],
                'appointment_document_id' => $input['document_id'] ?? null,
                'evidence_path' => $input['evidence_path'] ?? null,
                'appointed_by' => $actorId,
                'accepted_at' => null,
                'acceptance_signature' => null,
                'revoked_at' => null,
                'source' => 'MANUAL',
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $recordId = (int) DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('hr_person_id', $hrPersonId)
            ->where('competency_id', $competency->id)->value('id');

        $this->audit($companyId, $actorId, 'appointment.made', $recordId, null, [
            'competency' => $competency->code, 'hr_person_id' => $hrPersonId,
            'scope' => $input['scope'], 'regulatory_reference' => $competency->regulatory_reference,
        ]);

        return $recordId;
    }

    /** The appointee accepts. Until this happens the appointment is not in force. */
    public function acceptAppointment(int $companyId, int $recordId, string $signature, ?int $actorId = null): void
    {
        if (trim($signature) === '') {
            throw new RuntimeException('An appointment is accepted by signing it.');
        }

        DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('id', $recordId)
            ->update(['accepted_at' => now(), 'acceptance_signature' => $signature,
                'status' => 'COMPLIANT', 'updated_at' => now()]);

        $this->audit($companyId, $actorId, 'appointment.accepted', $recordId, null, ['accepted' => true]);
    }

    /**
     * Revoke on role change or termination (SHEQ-COMP-8). An appointment that outlives the job is
     * how a company ends up with a legally appointed first aider who left two years ago.
     */
    public function revoke(int $companyId, int $recordId, string $reason, ?int $actorId = null): void
    {
        DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->where('id', $recordId)
            ->update(['revoked_at' => now(), 'revocation_reason' => $reason,
                'status' => 'EXPIRED', 'updated_at' => now()]);

        $this->audit($companyId, $actorId, 'appointment.revoked', $recordId, null, ['reason' => $reason], $reason);
    }

    /**
     * The competency matrix (SHEQ-COMP-6): employees × competencies, with compliance percentages
     * per row and per column.
     *
     * @param  array<int,array<string,string|null>>  $people  hr_person_id => context
     * @return array{competencies:list<array<string,mixed>>, rows:list<array<string,mixed>>, by_competency:array<string,array<string,mixed>>, overall_percent:?float}
     */
    public function matrix(int $companyId, array $people): array
    {
        $records = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)
            ->whereIn('hr_person_id', array_keys($people))
            ->whereNull('deleted_at')->get()
            ->groupBy('hr_person_id');

        $competencyIds = [];
        $rows = [];
        $columnTotals = [];

        foreach ($people as $hrPersonId => $context) {
            $required = $this->requiredFor($companyId, (int) $hrPersonId, $context);
            $personRecords = ($records[$hrPersonId] ?? collect())->keyBy('competency_id');

            $cells = [];
            $compliant = 0;

            foreach ($required as $requirement) {
                $competencyIds[$requirement['competency_id']] = $requirement;
                $record = $personRecords[$requirement['competency_id']] ?? null;
                $status = $record ? $this->deriveStatus($record, $requirement['grace_days']) : 'NOT_STARTED';

                // EXPIRING still counts as compliant today: the ticket is valid, and treating it as
                // a failure would make every renewal window look like non-compliance.
                $isCompliant = in_array($status, ['COMPLIANT', 'EXPIRING', 'EXEMPT'], true);
                $compliant += $isCompliant ? 1 : 0;

                $columnTotals[$requirement['code']]['required'] = ($columnTotals[$requirement['code']]['required'] ?? 0) + 1;
                $columnTotals[$requirement['code']]['compliant'] = ($columnTotals[$requirement['code']]['compliant'] ?? 0) + ($isCompliant ? 1 : 0);

                $cells[$requirement['code']] = [
                    'status' => $status,
                    'expires_at' => $record->expires_at ?? null,
                    'blocks_work' => $requirement['blocks_work'],
                ];
            }

            $rows[] = [
                'hr_person_id' => (int) $hrPersonId,
                'cells' => $cells,
                'required' => count($required),
                'compliant' => $compliant,
                'percent' => $required === [] ? null : round(($compliant / count($required)) * 100, 1),
            ];
        }

        $byCompetency = [];
        foreach ($columnTotals as $code => $totals) {
            $byCompetency[$code] = [
                'required' => $totals['required'],
                'compliant' => $totals['compliant'],
                'percent' => $totals['required'] > 0
                    ? round(($totals['compliant'] / $totals['required']) * 100, 1) : null,
            ];
        }

        $totalRequired = array_sum(array_column($rows, 'required'));
        $totalCompliant = array_sum(array_column($rows, 'compliant'));

        return [
            'competencies' => array_values($competencyIds),
            'rows' => $rows,
            'by_competency' => $byCompetency,
            'overall_percent' => $totalRequired > 0
                ? round(($totalCompliant / $totalRequired) * 100, 1) : null,
        ];
    }

    /**
     * Records crossing a notification gate today (SHEQ-COMP-5: 90/60/30/14/7 and the day itself).
     *
     * Returns the gate crossed so a notifier can say "expires in 30 days" rather than repeating the
     * same message daily — the fastest way to teach people to ignore it.
     *
     * @return list<array<string,mixed>>
     */
    public function expiryNotificationsDue(int $companyId, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $records = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.company_id', $companyId)
            ->whereNotNull('r.expires_at')
            ->whereNull('r.revoked_at')
            ->whereNull('r.deleted_at')
            ->get(['r.id', 'r.hr_person_id', 'r.expires_at', 'c.code', 'c.title', 'c.lms_course_id',
                'c.blocks_work_when_expired']);

        $due = [];

        foreach ($records as $record) {
            $daysLeft = (int) $today->diffInDays(Carbon::parse($record->expires_at), false);

            if (! in_array($daysLeft, self::NOTIFY_AT_DAYS, true)) {
                continue;
            }

            $due[] = [
                'record_id' => $record->id,
                'hr_person_id' => $record->hr_person_id,
                'competency' => $record->code,
                'title' => $record->title,
                'expires_at' => $record->expires_at,
                'days_left' => $daysLeft,
                'gate' => $daysLeft,
                'blocks_work' => (bool) $record->blocks_work_when_expired,
                // SHEQ-COMP-5 wants the refresher auto-enrolled; the course reference is what LMS needs.
                'lms_course_id' => $record->lms_course_id,
            ];
        }

        return $due;
    }

    private function findCompetency(int $companyId, string $code): object
    {
        $competency = DB::table('sheq_competencies')
            ->where('company_id', $companyId)->where('code', $code)->whereNull('deleted_at')->first();

        if ($competency === null) {
            throw new RuntimeException("Competency `{$code}` is not defined for this company.");
        }

        return $competency;
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, ?array $after, ?string $reason = null
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Competency',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
