<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deviation Management (§5).
 *
 * A deviation is the request to depart from the normal procurement route, and it is the
 * transaction auditors examine first. The design follows from that:
 *
 *  - **Nothing is submitted without a compliance check.** `submit()` runs the rules engine and
 *    refuses on FAIL. A WARNING may be accepted, but only knowingly and by name — the acceptance is
 *    written into the check's results, so "who decided this was acceptable" always has an answer.
 *
 *  - **The approval route is selected, not chosen.** §5.2 wants chains configured by value,
 *    department, category, deviation type and risk level, and the requester must not be able to
 *    influence which one applies. The rules engine picks it from the deviation's own attributes.
 *
 *  - **A draft can be incomplete.** Validation to *save* is minimal; validation to *submit* is the
 *    tenant's full rule set. Someone must be able to start capturing an emergency deviation at
 *    17:00 and finish it in the morning, and a form that refuses to save half-finished work simply
 *    gets filled in afterwards from memory, which is worse for everyone.
 */
class DeviationService
{
    /**
     * §5.2. The intermediate review stages exist for tenants whose chain has them; a tenant with a
     * two-step chain moves DRAFT → SUBMITTED → MANAGEMENT_APPROVAL → APPROVED and never sees the
     * others.
     */
    public const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'WITHDRAWN'],
        'SUBMITTED' => ['COMPLIANCE_REVIEW', 'PROCUREMENT_REVIEW', 'FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL', 'REJECTED', 'DRAFT', 'WITHDRAWN'],
        'COMPLIANCE_REVIEW' => ['PROCUREMENT_REVIEW', 'FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL', 'REJECTED', 'DRAFT'],
        'PROCUREMENT_REVIEW' => ['FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL', 'REJECTED', 'DRAFT'],
        'FINANCIAL_REVIEW' => ['MANAGEMENT_APPROVAL', 'REJECTED', 'DRAFT'],
        'MANAGEMENT_APPROVAL' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['CLOSED'],
        'REJECTED' => ['CLOSED', 'DRAFT'],
        'WITHDRAWN' => [],
        'CLOSED' => [],
    ];

    public const RISK_LEVELS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
        private readonly RulesEngine $rules,
        private readonly ApprovalRouter $approvals,
        private readonly RecordService $records,
        private readonly SettingsService $settings,
    ) {}

    /** @param  array<string,mixed>  $data */
    public function create(int $companyId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $data) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'deviation');

            $type = ! empty($data['deviation_type_id'])
                ? DB::table('procgov_deviation_types')->find($data['deviation_type_id'])
                : null;

            $id = DB::table('procgov_deviations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $reference,
                'title' => $data['title'],
                'record_id' => $data['record_id'] ?? null,
                'procurement_reference' => $data['procurement_reference'] ?? null,
                'company_unit_id' => $data['company_unit_id'] ?? null,
                'requesting_officer_id' => $data['requesting_officer_id'] ?? auth()->id(),
                'procurement_value' => $data['procurement_value'] ?? null,
                'currency' => $data['currency'] ?? $this->settings->get($companyId)->currency,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'deviation_type_id' => $data['deviation_type_id'] ?? null,
                'deviation_type_code' => $type?->code,
                'reason' => $data['reason'] ?? null,
                'business_justification' => $data['business_justification'] ?? null,
                'circumstances' => $data['circumstances'] ?? null,
                'risk_assessment' => $data['risk_assessment'] ?? null,
                'risk_level' => $data['risk_level'] ?? $type?->default_risk_level ?? 'MEDIUM',
                'proposed_alternative' => $data['proposed_alternative'] ?? null,
                'financial_impact' => $data['financial_impact'] ?? null,
                'financial_impact_amount' => $data['financial_impact_amount'] ?? null,
                'requested_approval_date' => $data['requested_approval_date'] ?? null,
                'status' => 'DRAFT',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->logTransition($companyId, $id, null, 'DRAFT', 'Deviation raised');

            if (! empty($data['record_id'])) {
                $this->records->link($companyId, (int) $data['record_id'], [
                    'link_role' => 'deviation',
                    'entity_type' => 'procgov_deviations',
                    'entity_id' => $id,
                    'entity_reference' => $reference,
                    'label' => $data['title'],
                    'amount' => $data['procurement_value'] ?? null,
                    'state' => 'DRAFT',
                ]);
            }

            $this->audit->record($companyId, [
                'action' => 'created',
                'capability' => 'deviations',
                'entity_type' => 'procgov_deviations',
                'entity_id' => $id,
                'entity_reference' => $reference,
                'record_id' => $data['record_id'] ?? null,
                'summary' => "Raised deviation {$reference} — {$data['title']}",
            ]);

            return DB::table('procgov_deviations')->find($id);
        });
    }

    /** @param  array<string,mixed>  $data */
    public function update(int $companyId, int $deviationId, array $data): object
    {
        $deviation = DB::table('procgov_deviations')->find($deviationId);

        if (! $deviation) {
            throw new DomainRuleException('Deviation not found.');
        }

        if (! in_array($deviation->status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainRuleException(
                'A deviation under review cannot be edited. Withdraw it, or ask the reviewer to '
                .'return it to draft.'
            );
        }

        $before = array_intersect_key((array) $deviation, $data);

        DB::table('procgov_deviations')->where('id', $deviationId)->update(
            array_merge($data, ['updated_by' => auth()->id(), 'updated_at' => Carbon::now()])
        );

        $this->audit->record($companyId, [
            'action' => 'updated',
            'capability' => 'deviations',
            'entity_type' => 'procgov_deviations',
            'entity_id' => $deviationId,
            'entity_reference' => $deviation->reference,
            'record_id' => $deviation->record_id,
            'before' => $before,
            'after' => $data,
        ]);

        return DB::table('procgov_deviations')->find($deviationId);
    }

    /**
     * §5.4 automatic compliance check, without persisting — for a live form.
     *
     * @return array{result:string, failures:list<string>, warnings:list<string>, skipped:list<string>}
     */
    public function preflight(int $companyId, int $deviationId): array
    {
        $deviation = DB::table('procgov_deviations')->find($deviationId);

        if (! $deviation) {
            return ['result' => 'FAIL', 'failures' => ['Deviation not found.'], 'warnings' => [], 'skipped' => []];
        }

        return $this->rules->preview($this->asRuleSubject($companyId, $deviation));
    }

    /**
     * Submit for approval. Runs the compliance check, refuses on FAIL, and starts the chain the
     * rules engine selects.
     *
     * @param  bool  $acceptWarnings  the submitter has knowingly accepted the warnings
     * @return array{ok:bool, check:object|null, problems:list<string>}
     */
    public function submit(int $companyId, int $deviationId, bool $acceptWarnings = false, ?string $acceptanceReason = null): array
    {
        $deviation = DB::table('procgov_deviations')->find($deviationId);

        if (! $deviation) {
            return ['ok' => false, 'check' => null, 'problems' => ['Deviation not found.']];
        }

        if ($deviation->status !== 'DRAFT' && $deviation->status !== 'REJECTED') {
            return ['ok' => false, 'check' => null, 'problems' => ['This deviation has already been submitted.']];
        }

        $check = $this->rules->check($this->asRuleSubject($companyId, $deviation), 'pre_submit');

        if ($check->result === 'FAIL') {
            $failures = collect($check->results)
                ->where('outcome', 'FAIL')
                ->map(fn ($r) => $r->message ?: $r->rule_name)
                ->values()
                ->all();

            DB::table('procgov_deviations')->where('id', $deviationId)->update([
                'compliance_check_id' => $check->id,
                'compliance_result' => $check->result,
                'updated_at' => Carbon::now(),
            ]);

            return ['ok' => false, 'check' => $check, 'problems' => $failures];
        }

        if ($check->result === 'WARNING' && ! $acceptWarnings) {
            $warnings = collect($check->results)
                ->where('outcome', 'WARNING')
                ->map(fn ($r) => $r->message ?: $r->rule_name)
                ->values()
                ->all();

            DB::table('procgov_deviations')->where('id', $deviationId)->update([
                'compliance_check_id' => $check->id,
                'compliance_result' => $check->result,
                'updated_at' => Carbon::now(),
            ]);

            return ['ok' => false, 'check' => $check, 'problems' => $warnings];
        }

        return DB::transaction(function () use ($companyId, $deviationId, $deviation, $check, $acceptWarnings, $acceptanceReason) {
            $now = Carbon::now();

            // Accepting a warning is itself an audited act, recorded against the individual rule
            // outcomes so it survives with the check that produced them.
            if ($acceptWarnings && $check->result === 'WARNING') {
                DB::table('procgov_compliance_results')
                    ->where('check_id', $check->id)
                    ->where('outcome', 'WARNING')
                    ->update([
                        'acknowledged_by' => auth()->id(),
                        'acknowledged_at' => $now,
                        'acknowledgement_reason' => $acceptanceReason,
                        'updated_at' => $now,
                    ]);
            }

            $request = null;
            $problems = [];

            try {
                $request = $this->approvals->start(
                    'deviation',
                    $this->asRuleSubject($companyId, $deviation),
                    [
                        'label' => $deviation->title,
                        'record_id' => $deviation->record_id,
                        'due_days' => $this->settings->get($companyId)->escalation_days,
                    ],
                );
            } catch (\RuntimeException $e) {
                // No chain configured is a configuration problem, not a user error, and the
                // deviation must not silently sit in limbo looking submitted.
                return ['ok' => false, 'check' => $check, 'problems' => [$e->getMessage()]];
            }

            DB::table('procgov_deviations')->where('id', $deviationId)->update([
                'status' => 'SUBMITTED',
                'submitted_at' => $now,
                'submitted_by' => auth()->id(),
                'approval_request_id' => $request?->id,
                'compliance_check_id' => $check->id,
                'compliance_result' => $check->result,
                'rule_set_id' => $check->rule_set_id,
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            $this->logTransition($companyId, $deviationId, $deviation->status, 'SUBMITTED', $acceptanceReason);

            $this->audit->record($companyId, [
                'action' => 'submitted',
                'capability' => 'deviations',
                'entity_type' => 'procgov_deviations',
                'entity_id' => $deviationId,
                'entity_reference' => $deviation->reference,
                'record_id' => $deviation->record_id,
                'decision' => $check->result,
                'comment' => $acceptanceReason,
                'summary' => sprintf(
                    'Submitted deviation %s for approval (compliance: %s)',
                    $deviation->reference,
                    $check->result,
                ),
            ]);

            return ['ok' => true, 'check' => $check, 'problems' => $problems];
        });
    }

    /** Record the final decision once the approval chain completes. */
    public function decide(int $companyId, int $deviationId, string $decision, ?string $comment = null): object
    {
        return DB::transaction(function () use ($companyId, $deviationId, $decision, $comment) {
            $deviation = DB::table('procgov_deviations')->lockForUpdate()->find($deviationId);

            if (! $deviation) {
                throw new DomainRuleException('Deviation not found.');
            }

            $now = Carbon::now();
            $toStatus = $decision === 'APPROVED' ? 'APPROVED' : 'REJECTED';

            DB::table('procgov_deviations')->where('id', $deviationId)->update([
                'status' => $toStatus,
                'decision' => $decision,
                'decision_comment' => $comment,
                'decided_at' => $now,
                'decided_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            $this->logTransition($companyId, $deviationId, $deviation->status, $toStatus, $comment);

            if ($deviation->record_id) {
                $this->records->link($companyId, (int) $deviation->record_id, [
                    'link_role' => 'deviation',
                    'entity_type' => 'procgov_deviations',
                    'entity_id' => $deviationId,
                    'entity_reference' => $deviation->reference,
                    'label' => $deviation->title,
                    'amount' => $deviation->procurement_value,
                    'state' => $toStatus,
                ]);
            }

            $this->audit->record($companyId, [
                'action' => 'deviation_'.mb_strtolower($decision),
                'capability' => 'deviations',
                'entity_type' => 'procgov_deviations',
                'entity_id' => $deviationId,
                'entity_reference' => $deviation->reference,
                'record_id' => $deviation->record_id,
                'decision' => $decision,
                'comment' => $comment,
                'summary' => ucfirst(mb_strtolower($decision))." deviation {$deviation->reference}",
            ]);

            return DB::table('procgov_deviations')->find($deviationId);
        });
    }

    /** @throws \RuntimeException on a transition the workflow does not allow */
    public function transition(int $companyId, int $deviationId, string $toStatus, ?string $comment = null): object
    {
        $deviation = DB::table('procgov_deviations')->find($deviationId);

        if (! $deviation) {
            throw new DomainRuleException('Deviation not found.');
        }

        $allowed = self::TRANSITIONS[$deviation->status] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new DomainRuleException(
                "A deviation in {$deviation->status} cannot move to {$toStatus}."
                .($allowed === [] ? '' : ' Allowed: '.implode(', ', $allowed).'.')
            );
        }

        if (in_array($toStatus, ['APPROVED', 'REJECTED'], true)) {
            return $this->decide($companyId, $deviationId, $toStatus, $comment);
        }

        $now = Carbon::now();
        $update = ['status' => $toStatus, 'updated_by' => auth()->id(), 'updated_at' => $now];

        if ($toStatus === 'WITHDRAWN') {
            $update['withdrawn_at'] = $now;

            if ($deviation->approval_request_id) {
                $this->approvals->withdraw((int) $deviation->approval_request_id, $comment);
            }
        }

        if ($toStatus === 'CLOSED') {
            $update['closed_at'] = $now;
        }

        DB::table('procgov_deviations')->where('id', $deviationId)->update($update);
        $this->logTransition($companyId, $deviationId, $deviation->status, $toStatus, $comment);

        $this->audit->record($companyId, [
            'action' => 'status_changed',
            'capability' => 'deviations',
            'entity_type' => 'procgov_deviations',
            'entity_id' => $deviationId,
            'entity_reference' => $deviation->reference,
            'record_id' => $deviation->record_id,
            'before' => ['status' => $deviation->status],
            'after' => ['status' => $toStatus],
            'comment' => $comment,
        ]);

        return DB::table('procgov_deviations')->find($deviationId);
    }

    public function history(int $companyId, int $deviationId): Collection
    {
        return DB::table('procgov_deviation_transitions')
            ->where('company_id', $companyId)
            ->where('deviation_id', $deviationId)
            ->orderBy('occurred_at')
            ->get();
    }

    /** §5 reporting: the shape of deviation activity over a period. */
    public function statistics(int $companyId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->get(['status', 'risk_level', 'deviation_type_code', 'procurement_value',
                'compliance_result', 'submitted_at', 'decided_at', 'company_unit_id']);

        $decided = $rows->filter(fn ($d) => $d->submitted_at && $d->decided_at);

        return [
            'total' => $rows->count(),
            'approved' => $rows->where('status', 'APPROVED')->count(),
            'rejected' => $rows->where('status', 'REJECTED')->count(),
            'pending' => $rows->whereNotIn('status', ['APPROVED', 'REJECTED', 'CLOSED', 'WITHDRAWN'])->count(),
            'value' => round((float) $rows->sum('procurement_value'), 2),
            'by_risk' => $rows->groupBy('risk_level')->map->count(),
            'by_type' => $rows->groupBy('deviation_type_code')->map->count(),
            'compliance_fails' => $rows->where('compliance_result', 'FAIL')->count(),
            'avg_decision_days' => $decided->isEmpty() ? null : round(
                $decided->avg(fn ($d) => Carbon::parse($d->submitted_at)->diffInHours(Carbon::parse($d->decided_at)) / 24),
                1,
            ),
        ];
    }

    public function asRuleSubject(int $companyId, object $deviation): RuleSubject
    {
        return new RuleSubject(
            companyId: $companyId,
            type: 'procgov_deviations',
            id: (int) $deviation->id,
            reference: $deviation->reference,
            fields: (array) $deviation,
            amount: $deviation->procurement_value !== null ? (float) $deviation->procurement_value : null,
            category: null,
            companyUnitId: $deviation->company_unit_id ? (int) $deviation->company_unit_id : null,
            method: 'SOLE_SOURCE',
            subtype: $deviation->deviation_type_code,
            riskLevel: $deviation->risk_level,
            status: $deviation->status,
            recordId: $deviation->record_id ? (int) $deviation->record_id : null,
            supplierId: $deviation->supplier_id ? (int) $deviation->supplier_id : null,
        );
    }

    private function logTransition(int $companyId, int $deviationId, ?string $from, string $to, ?string $comment): void
    {
        $now = Carbon::now();

        $last = DB::table('procgov_deviation_transitions')
            ->where('deviation_id', $deviationId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        DB::table('procgov_deviation_transitions')->insert([
            'company_id' => $companyId,
            'deviation_id' => $deviationId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => auth()->id(),
            'actor_label' => auth()->user()?->email,
            'comment' => $comment,
            // Cast, and round, deliberately: Carbon returns a float with fractional seconds, and
            // the column is a bigint — an unrounded value is rejected outright by Postgres.
            'seconds_in_from_status' => $last
                ? (int) max(0, round(Carbon::parse($last->occurred_at)->diffInSeconds($now)))
                : null,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
