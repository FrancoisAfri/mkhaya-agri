<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Risk assessment — HIRA (§5.3).
 *
 * The rules that make this a HIRA rather than a spreadsheet with a database behind it:
 *
 * - **Approval authority is derived, not chosen** (SHEQ-RA-3). The highest residual band sets who
 *   must sign. A site officer cannot approve an assessment carrying a critical residual risk, and
 *   the check is here rather than in a UI that can be bypassed by an API call.
 * - **Weak-control detection** (SHEQ-RA-2). Where a high or critical risk is controlled only by
 *   administrative measures or PPE, that is reported as a finding. Telling people to be careful is
 *   the least effective control and the easiest to write down.
 * - **Versioning** (SHEQ-RA-4). Approving a revision supersedes its predecessor, which stays
 *   readable forever. An assessment that was in force when someone was hurt must remain exactly as
 *   it was, whatever has been learned since.
 * - **Participation** (SHEQ-RA-5). Construction Regulations and the MHSA both require demonstrable
 *   worker participation, so the people in the room are recorded and can sign.
 */
class RiskAssessmentService
{
    public function __construct(
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /** Bands considered high enough that PPE/administrative-only control is a finding. */
    private const WEAK_CONTROL_BANDS = ['High', 'Critical'];

    private const WEAK_LEVELS = ['ADMINISTRATIVE', 'PPE'];

    /** The matrix for a company, creating the default on first use (SHEQ-RA-1). */
    public function matrixFor(int $companyId, ?int $matrixId = null): RiskMatrix
    {
        $row = $matrixId
            ? DB::table('sheq_risk_matrices')->where('company_id', $companyId)->where('id', $matrixId)->first()
            : DB::table('sheq_risk_matrices')->where('company_id', $companyId)
                ->where('is_default', true)->whereNull('deleted_at')->first();

        if ($row === null && $matrixId !== null) {
            throw new RuntimeException('Risk matrix not found for this company.');
        }

        return $row ? RiskMatrix::fromRow($row) : $this->createDefaultMatrix($companyId);
    }

    public function createDefaultMatrix(int $companyId): RiskMatrix
    {
        $definition = RiskMatrix::defaultDefinition();

        $id = DB::table('sheq_risk_matrices')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'name' => 'Default 5×5',
            'likelihood_levels' => $definition['likelihood_levels'],
            'severity_levels' => $definition['severity_levels'],
            'likelihood_labels' => json_encode($definition['likelihood_labels']),
            'severity_labels' => json_encode($definition['severity_labels']),
            'bands' => json_encode($definition['bands']),
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->matrixFor($companyId, $id);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:int, uuid:string, reference:string, version:int}
     */
    public function create(int $companyId, array $input, ?int $actorId = null): array
    {
        $matrix = $this->matrixFor($companyId, $input['matrix_id'] ?? null);

        return DB::transaction(function () use ($companyId, $input, $actorId, $matrix) {
            $uuid = (string) Str::uuid();
            $reference = $input['reference'] ?? $this->nextReference($companyId);

            $id = DB::table('sheq_risk_assessments')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'reference' => $reference,
                'ra_type' => $input['ra_type'] ?? 'BASELINE',
                'title' => $input['title'],
                'scope' => $input['scope'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'matrix_id' => $matrix->id,
                'status' => 'DRAFT',
                'version' => $input['version'] ?? 1,
                'supersedes_id' => $input['supersedes_id'] ?? null,
                'effective_from' => $input['effective_from'] ?? null,
                'review_due' => $input['review_due'] ?? Carbon::now()->addYear()->toDateString(),
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'risk_assessment.created', $id, null,
                ['reference' => $reference, 'title' => $input['title'], 'matrix' => $matrix->name]);

                        /*
             * Open the approval chain where one is configured.
             *
             * Opened when the subject is raised rather than when it is acted on, so an approver
             * has the time in between to sign. Opening it at the moment of effect would mean the
             * first attempt always failed and somebody had to try twice.
             */
            $this->approvals->openIfConfigured($companyId, [
                'subject_type' => 'RISK_ASSESSMENT',
                'subject_id' => $id,
                'subject_label' => $reference,
                'site_id' => $input['site_id'] ?? null,
                'requested_by' => (int) ($actorId ?? 0),
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference, 'version' => 1];
        });
    }

    /**
     * Add a risk line and its controls. Bands are resolved from the matrix at write time so the
     * stored band always matches the score the assessor saw.
     *
     * @param  array<string,mixed>  $line
     * @param  list<array<string,mixed>>  $controls
     */
    public function addLine(int $companyId, string $assessmentUuid, array $line, array $controls = []): int
    {
        $assessment = $this->find($companyId, $assessmentUuid);

        if ($assessment->status !== 'DRAFT' && $assessment->status !== 'IN_REVIEW') {
            throw new RuntimeException(
                'An approved assessment cannot be edited (SHEQ-RA-4). Create a revision instead.'
            );
        }

        $matrix = $this->matrixFor($companyId, $assessment->matrix_id);

        return DB::transaction(function () use ($companyId, $assessment, $line, $controls, $matrix) {
            $inherentScore = $matrix->score((int) $line['inherent_likelihood'], (int) $line['inherent_severity']);

            $residualBand = null;
            if (isset($line['residual_likelihood'], $line['residual_severity'])) {
                $residualBand = $matrix->bandFor(
                    $matrix->score((int) $line['residual_likelihood'], (int) $line['residual_severity'])
                )['label'] ?? null;
            }

            $sequence = (int) DB::table('sheq_risk_lines')
                ->where('risk_assessment_id', $assessment->id)->max('sequence') + 1;

            $lineId = DB::table('sheq_risk_lines')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'risk_assessment_id' => $assessment->id,
                'sequence' => $sequence,
                'activity' => $line['activity'],
                'hazard' => $line['hazard'],
                'potential_harm' => $line['potential_harm'] ?? null,
                'inherent_likelihood' => $line['inherent_likelihood'],
                'inherent_severity' => $line['inherent_severity'],
                'inherent_band' => $matrix->bandFor($inherentScore)['label'] ?? null,
                'residual_likelihood' => $line['residual_likelihood'] ?? null,
                'residual_severity' => $line['residual_severity'] ?? null,
                'residual_band' => $residualBand,
                'risk_owner_id' => $line['risk_owner_id'] ?? null,
                'is_acceptable' => $line['is_acceptable'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($controls as $control) {
                DB::table('sheq_controls')->insert([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'risk_line_id' => $lineId,
                    'hierarchy_level' => $control['hierarchy_level'],
                    'description' => $control['description'],
                    'control_type' => $control['control_type'] ?? 'STANDARD',
                    'is_implemented' => $control['is_implemented'] ?? false,
                    'verification_method' => $control['verification_method'] ?? null,
                    'verification_frequency' => $control['verification_frequency'] ?? null,
                    'linked_document_id' => $control['linked_document_id'] ?? null,
                    'linked_competency_id' => $control['linked_competency_id'] ?? null,
                    'linked_inspection_template_id' => $control['linked_inspection_template_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $lineId;
        });
    }

    /**
     * Lines whose only controls sit at the bottom of the hierarchy (SHEQ-RA-2).
     *
     * Surfaced as a list rather than a boolean because the useful question is "which risks are we
     * managing with a sign and a hard hat", not "does this assessment have a problem somewhere".
     *
     * @return list<array<string,mixed>>
     */
    public function weakControlFindings(int $companyId, string $assessmentUuid): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);

        $lines = DB::table('sheq_risk_lines')
            ->where('risk_assessment_id', $assessment->id)->whereNull('deleted_at')
            ->get(['id', 'sequence', 'activity', 'hazard', 'residual_band']);

        $findings = [];

        foreach ($lines as $line) {
            if (! in_array($line->residual_band, self::WEAK_CONTROL_BANDS, true)) {
                continue;
            }

            $levels = DB::table('sheq_controls')->where('risk_line_id', $line->id)
                ->whereNull('deleted_at')->pluck('hierarchy_level')->all();

            // No controls at all is the strongest form of the same finding.
            $onlyWeak = $levels === [] || array_diff(array_unique($levels), self::WEAK_LEVELS) === [];

            if ($onlyWeak) {
                $findings[] = [
                    'line_sequence' => $line->sequence,
                    'activity' => $line->activity,
                    'hazard' => $line->hazard,
                    'residual_band' => $line->residual_band,
                    'controls' => $levels,
                    'message' => sprintf(
                        'A %s residual risk ("%s") is controlled only by %s. The hierarchy of control '
                        .'asks for elimination, substitution or engineering first.',
                        strtolower($line->residual_band),
                        $line->hazard,
                        $levels === [] ? 'nothing recorded' : strtolower(implode(' and ', array_unique($levels))),
                    ),
                ];
            }
        }

        return $findings;
    }

    /** The role required to approve, derived from the highest residual band (SHEQ-RA-3). */
    public function requiredApprovalRole(int $companyId, string $assessmentUuid): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);
        $matrix = $this->matrixFor($companyId, $assessment->matrix_id);

        $highest = DB::table('sheq_risk_lines')
            ->where('risk_assessment_id', $assessment->id)->whereNull('deleted_at')
            ->max('residual_score');

        // No residual ratings yet: the inherent risk is what stands, and it is what must be signed.
        if ($highest === null) {
            $highest = DB::table('sheq_risk_lines')
                ->where('risk_assessment_id', $assessment->id)->whereNull('deleted_at')
                ->max('inherent_score');
        }

        $band = $highest === null ? null : $matrix->bandFor((int) $highest);

        return [
            'highest_score' => $highest === null ? null : (int) $highest,
            'band' => $band['label'] ?? null,
            'role' => $band['max_approval_role'] ?? null,
        ];
    }

    /**
     * Approve. Refuses when the approver's role is below what the highest residual band demands,
     * and refuses an assessment with no participants recorded.
     *
     * @param  list<string>  $approverRoles
     */
    public function approve(
        int $companyId, string $assessmentUuid, ?int $actorId, array $approverRoles, ?string $effectiveFrom = null
    ): void {
        $assessment = $this->find($companyId, $assessmentUuid);

        if ($assessment->status === 'APPROVED') {
            throw new RuntimeException('This assessment is already approved.');
        }
        /*
         * An approval workflow, where the tenant configured one.
         *
         * The Approvals screen has always offered a workflow for this subject and nothing read it,
         * so a tenant could build a chain, save it, and watch it be ignored. Checked here rather
         * than opened here: the chain is opened when the subject is raised, and this is the moment
         * it has to be honoured.
         *
         * With no workflow configured this is a no-op, so a tenant that has not set one up keeps
         * exactly the behaviour they had.
         */
        $blocked = $this->approvals->blockingReason($companyId, 'RISK_ASSESSMENT', (int) $assessment->id, null, $assessment->site_id);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }


        $participants = DB::table('sheq_risk_assessment_participants')
            ->where('risk_assessment_id', $assessment->id)->count();

        if ($participants === 0) {
            throw new RuntimeException(
                'A risk assessment cannot be approved with no recorded participants (SHEQ-RA-5). '
                .'Construction Regulations and the MHSA both require demonstrable worker participation.'
            );
        }

        $required = $this->requiredApprovalRole($companyId, $assessmentUuid);
        $matrix = $this->matrixFor($companyId, $assessment->matrix_id);

        // Seniority satisfies a lower bar — an executive may sign a medium risk — but a practitioner
        // can never sign a critical one. The ladder comes from the matrix's band order, so a tenant
        // with different role names gets correct behaviour without a code change (SHEQ-RA-1/RA-3).
        if ($required['highest_score'] !== null
            && ! $matrix->satisfiesApproval((int) $required['highest_score'], $approverRoles)) {
            throw new RuntimeException(sprintf(
                'This assessment carries a %s residual risk and must be approved by %s or more senior '
                .'(SHEQ-RA-3).',
                strtolower((string) $required['band']), $required['role']
            ));
        }

        DB::transaction(function () use ($assessment, $companyId, $actorId, $required, $effectiveFrom) {
            // Supersede the predecessor rather than deleting it — it stays readable forever.
            if ($assessment->supersedes_id) {
                DB::table('sheq_risk_assessments')->where('id', $assessment->supersedes_id)
                    ->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
            }

            DB::table('sheq_risk_assessments')->where('id', $assessment->id)->update([
                'status' => 'APPROVED',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'approval_band' => $required['band'],
                'approval_role_required' => $required['role'],
                'effective_from' => $effectiveFrom ?? Carbon::now()->toDateString(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'risk_assessment.approved', $assessment->id,
                ['status' => $assessment->status],
                ['status' => 'APPROVED', 'band' => $required['band'], 'role' => $required['role']]);
        });
    }

    /**
     * Start a revision (SHEQ-RA-4). The new version copies the lines and controls forward; the old
     * one is only superseded when the revision is approved, so there is never a gap with no
     * assessment in force.
     */
    public function createRevision(int $companyId, string $assessmentUuid, ?int $actorId, string $trigger = 'SCHEDULE'): array
    {
        $source = $this->find($companyId, $assessmentUuid);

        return DB::transaction(function () use ($companyId, $source, $actorId, $trigger) {
            $uuid = (string) Str::uuid();

            $newId = DB::table('sheq_risk_assessments')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'reference' => $source->reference,
                'ra_type' => $source->ra_type,
                'title' => $source->title,
                'scope' => $source->scope,
                'site_id' => $source->site_id,
                'matrix_id' => $source->matrix_id,
                'status' => 'DRAFT',
                'version' => $source->version + 1,
                'supersedes_id' => $source->id,
                'review_due' => Carbon::now()->addYear()->toDateString(),
                'review_trigger' => $trigger,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('sheq_risk_lines')->where('risk_assessment_id', $source->id)
                ->whereNull('deleted_at')->orderBy('sequence')->get() as $line) {
                $newLineId = DB::table('sheq_risk_lines')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
                    'risk_assessment_id' => $newId, 'sequence' => $line->sequence,
                    'activity' => $line->activity, 'hazard' => $line->hazard,
                    'potential_harm' => $line->potential_harm,
                    'inherent_likelihood' => $line->inherent_likelihood,
                    'inherent_severity' => $line->inherent_severity,
                    'inherent_band' => $line->inherent_band,
                    'residual_likelihood' => $line->residual_likelihood,
                    'residual_severity' => $line->residual_severity,
                    'residual_band' => $line->residual_band,
                    'risk_owner_id' => $line->risk_owner_id, 'is_acceptable' => $line->is_acceptable,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                foreach (DB::table('sheq_controls')->where('risk_line_id', $line->id)
                    ->whereNull('deleted_at')->get() as $control) {
                    DB::table('sheq_controls')->insert([
                        'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
                        'risk_line_id' => $newLineId, 'hierarchy_level' => $control->hierarchy_level,
                        'description' => $control->description, 'control_type' => $control->control_type,
                        'is_implemented' => $control->is_implemented,
                        'verification_method' => $control->verification_method,
                        'verification_frequency' => $control->verification_frequency,
                        'linked_document_id' => $control->linked_document_id,
                        'linked_competency_id' => $control->linked_competency_id,
                        'linked_inspection_template_id' => $control->linked_inspection_template_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->audit($companyId, $actorId, 'risk_assessment.revised', $newId, null,
                ['from_version' => $source->version, 'to_version' => $source->version + 1, 'trigger' => $trigger]);

            return ['id' => $newId, 'uuid' => $uuid, 'reference' => $source->reference,
                'version' => $source->version + 1];
        });
    }

    /** Record who took part, and their signature where captured (SHEQ-RA-5). */
    public function addParticipant(int $companyId, string $assessmentUuid, array $participant): int
    {
        $assessment = $this->find($companyId, $assessmentUuid);

        return DB::table('sheq_risk_assessment_participants')->insertGetId([
            'company_id' => $companyId,
            'risk_assessment_id' => $assessment->id,
            'user_id' => $participant['user_id'] ?? null,
            'hr_person_id' => $participant['hr_person_id'] ?? null,
            'name' => $participant['name'],
            'participant_role' => $participant['participant_role'] ?? 'WORKER',
            'signed_at' => ! empty($participant['signature']) ? now() : null,
            'signature' => $participant['signature'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The approved assessment in force for a site at a moment (used by the context snapshot and,
     * later, by Permit to Work — SHEQ-PTW-1 refuses a permit without one).
     */
    public function activeForSite(int $companyId, ?int $siteId, ?Carbon $at = null): ?object
    {
        $at ??= Carbon::now();

        return DB::table('sheq_risk_assessments')
            ->where('company_id', $companyId)
            ->where('status', 'APPROVED')
            ->when($siteId, fn ($q) => $q->where(fn ($w) => $w->where('site_id', $siteId)->orWhereNull('site_id')))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at->toDateString()))
            ->whereNull('deleted_at')
            ->orderByRaw('site_id is null')      // a site-specific assessment beats a company-wide one
            ->orderByDesc('version')
            ->first();
    }

    private function find(int $companyId, string $uuid): object
    {
        $assessment = DB::table('sheq_risk_assessments')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($assessment === null) {
            throw new RuntimeException('Risk assessment not found.');
        }

        return $assessment;
    }

    private function nextReference(int $companyId): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('assessment_prefix') ?? 'RA';
        $year = Carbon::now()->year;

        $last = DB::table('sheq_risk_assessments')->where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")->orderByDesc('reference')->value('reference');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId, ?array $before, ?array $after
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'RiskAssessment',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
        ]);
    }
}
