<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conflict of interest (§1.3).
 *
 * The five statuses are the brief's, verbatim: NOT_COMPLETED, DECLARED, CONFLICT_IDENTIFIED,
 * RECUSED, APPROVED_TO_PARTICIPATE. Two of them permit scoring — DECLARED (nothing to declare) and
 * APPROVED_TO_PARTICIPATE (a conflict was disclosed and an administrator judged it immaterial).
 * The other three do not, and `mayEvaluate()` is the single place that decides, so no screen can
 * accidentally admit a conflicted evaluator by forgetting a check.
 *
 * Declarations accumulate rather than overwrite. "Declared no conflict on the 3rd, then declared a
 * conflict on the 11th once the bidder list was published" is the sequence an investigation asks
 * about, and a status column alone loses it.
 *
 * Raising a conflict freezes the evaluator immediately — §1.3 says evaluation activity stops until
 * it is resolved — and their existing draft scores are left untouched rather than deleted, because
 * if the conflict is later judged immaterial they should not have to start again.
 */
class ConflictOfInterestService
{
    public const STATUSES = [
        'NOT_COMPLETED',
        'DECLARED',
        'CONFLICT_IDENTIFIED',
        'RECUSED',
        'APPROVED_TO_PARTICIPATE',
    ];

    private const MAY_EVALUATE = ['DECLARED', 'APPROVED_TO_PARTICIPATE'];

    public function __construct(private readonly AuditLedger $audit) {}

    /**
     * Record an evaluator's declaration.
     *
     * @param  array<string,mixed>  $data
     *                                     has_conflict         bool
     *                                     nature_of_interest   string|null  required when has_conflict
     *                                     related_bidder_id    int|null
     *                                     declaration_statement string|null
     */
    public function declare(int $companyId, int $evaluatorId, array $data): object
    {
        $evaluator = DB::table('procgov_review_evaluators')->find($evaluatorId);

        if (! $evaluator) {
            throw new DomainRuleException('Evaluator not found on this review.');
        }

        $hasConflict = (bool) ($data['has_conflict'] ?? false);

        if ($hasConflict && trim((string) ($data['nature_of_interest'] ?? '')) === '') {
            throw new DomainRuleException('A declared conflict must describe the nature of the interest.');
        }

        $status = $hasConflict ? 'CONFLICT_IDENTIFIED' : 'DECLARED';

        return DB::transaction(function () use ($companyId, $evaluator, $evaluatorId, $data, $hasConflict, $status) {
            $now = Carbon::now();

            $supplierName = null;

            if (! empty($data['related_bidder_id'])) {
                $supplierName = DB::table('procgov_bidders')
                    ->where('id', $data['related_bidder_id'])
                    ->value('supplier_name');
            }

            $declarationId = DB::table('procgov_coi_declarations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $evaluator->review_id,
                'evaluator_id' => $evaluatorId,
                'status' => $status,
                'has_conflict' => $hasConflict,
                'nature_of_interest' => $data['nature_of_interest'] ?? null,
                'related_bidder_id' => $data['related_bidder_id'] ?? null,
                'related_supplier_name' => $supplierName,
                'declaration_statement' => $data['declaration_statement'] ?? null,
                'declared_at' => $now,
                'ip' => request()->ip(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procgov_review_evaluators')->where('id', $evaluatorId)->update([
                'coi_status' => $status,
                'coi_declared_at' => $now,
                'confidentiality_accepted_at' => $evaluator->confidentiality_accepted_at
                    ?? (($data['accept_confidentiality'] ?? false) ? $now : null),
                // Frozen out of scoring until an administrator resolves it.
                'status' => $hasConflict ? 'ASSIGNED' : $evaluator->status,
                'updated_at' => $now,
            ]);

            $review = DB::table('procgov_tender_reviews')->find($evaluator->review_id);

            $this->audit->record($companyId, [
                'action' => $hasConflict ? 'coi_conflict_declared' : 'coi_declared',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_review_evaluators',
                'entity_id' => $evaluatorId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'decision' => $status,
                'comment' => $data['nature_of_interest'] ?? null,
                'summary' => $hasConflict
                    ? "Declared a conflict of interest on {$review?->reference}"
                    : "Declared no conflict of interest on {$review?->reference}",
            ]);

            return DB::table('procgov_coi_declarations')->find($declarationId);
        });
    }

    /**
     * An administrator resolves a declared conflict.
     *
     * @param  string  $resolution  CLEARED | RECUSED | REMOVED | ESCALATED
     */
    public function resolve(int $companyId, int $declarationId, string $resolution, ?string $note = null): void
    {
        if (trim((string) $note) === '' && $resolution !== 'CLEARED') {
            throw new DomainRuleException('Resolving a declared conflict requires a note explaining the decision.');
        }

        DB::transaction(function () use ($companyId, $declarationId, $resolution, $note) {
            $declaration = DB::table('procgov_coi_declarations')->lockForUpdate()->find($declarationId);

            if (! $declaration) {
                throw new DomainRuleException('Declaration not found.');
            }

            $now = Carbon::now();

            DB::table('procgov_coi_declarations')->where('id', $declarationId)->update([
                'resolution' => $resolution,
                'resolution_note' => $note,
                'resolved_by' => auth()->id(),
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);

            $evaluatorStatus = match ($resolution) {
                'CLEARED' => ['coi_status' => 'APPROVED_TO_PARTICIPATE', 'status' => 'ASSIGNED'],
                'RECUSED' => ['coi_status' => 'RECUSED', 'status' => 'RECUSED', 'is_voting' => false],
                'REMOVED' => ['coi_status' => 'RECUSED', 'status' => 'REMOVED', 'is_voting' => false],
                default => ['coi_status' => 'CONFLICT_IDENTIFIED'],
            };

            DB::table('procgov_review_evaluators')
                ->where('id', $declaration->evaluator_id)
                ->update(array_merge($evaluatorStatus, ['updated_at' => $now]));

            // A recused evaluator's drafts must not sit in the pool waiting to be counted. Their
            // submitted evaluations are withdrawn — kept as rows, excluded from scoring.
            if (in_array($resolution, ['RECUSED', 'REMOVED'], true)) {
                DB::table('procgov_evaluations')
                    ->where('evaluator_id', $declaration->evaluator_id)
                    ->whereIn('status', ['DRAFT', 'SUBMITTED', 'REOPENED'])
                    ->update(['status' => 'WITHDRAWN', 'is_locked' => true, 'updated_at' => $now]);
            }

            $review = DB::table('procgov_tender_reviews')->find($declaration->review_id);

            $this->audit->record($companyId, [
                'action' => 'coi_resolved',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_coi_declarations',
                'entity_id' => $declarationId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'decision' => $resolution,
                'comment' => $note,
                'summary' => "Resolved a conflict-of-interest declaration on {$review?->reference} as {$resolution}",
            ]);
        });
    }

    /** Accept the confidentiality undertaking (§1.2), separately from the conflict declaration. */
    public function acceptConfidentiality(int $companyId, int $evaluatorId): void
    {
        DB::table('procgov_review_evaluators')->where('id', $evaluatorId)->update([
            'confidentiality_accepted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'confidentiality_accepted',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_review_evaluators',
            'entity_id' => $evaluatorId,
            'summary' => 'Accepted the evaluation confidentiality undertaking',
        ]);
    }

    /** The single place that decides whether an evaluator may score. */
    public function mayEvaluate(object $evaluator): bool
    {
        return in_array($evaluator->coi_status, self::MAY_EVALUATE, true)
            && ! in_array($evaluator->status, ['RECUSED', 'REMOVED'], true);
    }

    /** Everyone an administrator still has to deal with on a review. */
    public function outstanding(int $companyId, int $reviewId): Collection
    {
        return DB::table('procgov_review_evaluators as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.company_id', $companyId)
            ->where('e.review_id', $reviewId)
            ->whereIn('e.coi_status', ['NOT_COMPLETED', 'CONFLICT_IDENTIFIED'])
            ->whereNotIn('e.status', ['REMOVED'])
            ->get([
                'e.id', 'e.uuid', 'e.coi_status', 'e.member_role', 'e.is_external', 'e.external_name',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(function ($e) {
                $e->display_name = $e->is_external
                    ? $e->external_name
                    : (trim((string) $e->user_name) ?: $e->user_email);
                unset($e->user_name);

                return $e;
            });
    }

    public function declarations(int $companyId, int $reviewId): Collection
    {
        return DB::table('procgov_coi_declarations as d')
            ->join('procgov_review_evaluators as e', 'e.id', '=', 'd.evaluator_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('d.company_id', $companyId)
            ->where('d.review_id', $reviewId)
            ->orderByDesc('d.declared_at')
            ->get([
                'd.uuid', 'd.status', 'd.has_conflict', 'd.nature_of_interest',
                'd.related_supplier_name', 'd.declared_at', 'd.resolution', 'd.resolution_note',
                'd.resolved_at', 'e.is_external', 'e.external_name', 'e.member_role',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(function ($d) {
                $d->declared_by = $d->is_external
                    ? $d->external_name
                    : (trim((string) $d->user_name) ?: $d->user_email);
                unset($d->user_name);

                return $d;
            });
    }
}
