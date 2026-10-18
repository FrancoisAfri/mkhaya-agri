<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\CompletionRecord;
use App\Modules\Operations\ProjectManagement\Models\InspectionRequest;
use App\Modules\Operations\ProjectManagement\Models\MaterialTest;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\SiteInstruction;
use App\Modules\Operations\ProjectManagement\Models\Snag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Site instructions, material tests, inspection requests and completion (§23).
 *
 * The connecting idea across all four: each one is a record that has to exist *before* the thing it
 * documents is settled. An instruction is filed before anybody agrees whether it is a variation; a
 * cube is logged before the lab reports; an inspection is requested before the work is covered; a
 * completion certificate is issued while the snag list is still open. A module that only recorded
 * settled facts would be useless for every one of them.
 */
class SiteRecordService
{
    public function __construct(
        private readonly InternalEmitter $emitter,
        private readonly RegisterService $registers,
        // Snags are raised through the pack rather than by inserting rows: it owns the reference
        // numbering and the `inspection.failed` emission, and a second way of creating one would
        // produce snags with no reference that no register can quote.
        private readonly DeliveryPackService $pack,
    ) {
    }

    // ── Site instructions ───────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function recordInstruction(Project $project, array $data): SiteInstruction
    {
        return DB::transaction(function () use ($project, $data) {
            $si = SiteInstruction::create([
                ...$data,
                'project_id' => $project->id,
                'number'     => $this->nextNumber(SiteInstruction::class, $project, 'SI', 'number'),
                'created_by' => Auth::id(),
            ]);

            // On the timeline, because an instruction is a thing that happened on site on a date —
            // and the date is the whole argument when it is disputed later.
            $this->emitter->emit($project, 'site.visit', [
                'subject_type' => 'project',
                'subject_id'   => $project->uuid,
                'source_ref'   => 'site_instruction:' . $si->uuid,
                'occurred_at'  => $si->issued_on->toIso8601String(),
                'payload'      => ['purpose' => 'site_instruction', 'reference' => $si->number],
            ]);

            return $si;
        });
    }

    /**
     * Turn an instruction the contractor claimed as a variation into a change request.
     *
     * The link matters more than the convenience: it is what lets somebody answer "which
     * instructions did we ever get paid for", which is the question a final account turns on.
     */
    public function raiseVariation(SiteInstruction $si): SiteInstruction
    {
        if ($si->change_request_id !== null) {
            throw new RuntimeException(__('project_management.site_records.already_a_variation'));
        }

        return DB::transaction(function () use ($si) {
            $change = $this->registers->createChange($si->project, [
                'title'        => $si->title,
                'kind'         => 'scope',
                'description'  => $si->instruction,
                'reason'       => __('project_management.site_records.raised_from_si', ['number' => $si->number]),
                'impact_scope' => $si->instruction,
            ]);

            $si->update(['change_request_id' => $change->id, 'claimed_as_variation' => true]);

            return $si->refresh();
        });
    }

    // ── Material tests ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function logTest(Project $project, array $data): MaterialTest
    {
        return MaterialTest::create([
            ...$data,
            'project_id' => $project->id,
            'reference'  => $data['reference'] ?? $this->nextNumber(MaterialTest::class, $project, 'T', 'reference'),
            'result'     => 'pending',
        ]);
    }

    /**
     * The lab has reported.
     *
     * A failure raises a non-conformance automatically and links back to the test. Leaving that to
     * a person is how a failed cube ends up as a line in a spreadsheet with nothing done about it —
     * the pour it came from is already buried.
     */
    public function recordResult(MaterialTest $test, array $data): MaterialTest
    {
        return DB::transaction(function () use ($test, $data) {
            $test->update([
                'result'       => $data['result'],
                'result_on'    => $data['result_on'] ?? now()->toDateString(),
                'result_value' => $data['result_value'] ?? null,
                'lab'          => $data['lab'] ?? $test->lab,
                'notes'        => $data['notes'] ?? $test->notes,
            ]);

            if ($test->result === 'fail' && $test->snag_id === null) {
                // A failed cube is a non-conformance whether or not anybody gets round to raising
                // one, and the pour it came from is already buried. Raising it here is the
                // difference between a failure that gets acted on and a line in a spreadsheet.
                $snag = $this->pack->raiseSnag($test->project, [
                    'kind'        => 'ncr',
                    'severity'    => 'critical',
                    'location'    => $test->element,
                    'title'       => __('project_management.site_records.failed_test_title', [
                        'reference' => $test->reference,
                        'element'   => $test->element ?: '—',
                    ]),
                    'description' => __('project_management.site_records.failed_test_body', [
                        'result'    => (string) $test->result_value,
                        'specified' => (string) $test->specified_value,
                        'unit'      => (string) $test->unit,
                    ]),
                ]);

                $test->update(['snag_id' => $snag->id]);
            }

            return $test->refresh();
        });
    }

    // ── Inspection requests ─────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function requestInspection(Project $project, array $data): InspectionRequest
    {
        return InspectionRequest::create([
            ...$data,
            'project_id'   => $project->id,
            'number'       => $this->nextNumber(InspectionRequest::class, $project, 'IR', 'number'),
            // Stamped here rather than taken from the form: the notice period is only meaningful
            // if the "asked at" time is the server's and not the requester's.
            'requested_at' => now(),
            'status'       => 'requested',
            'created_by'   => Auth::id(),
        ]);
    }

    /** Record the outcome. A failure raises a snag, for the same reason a failed test does. */
    public function recordInspection(InspectionRequest $ir, array $data): InspectionRequest
    {
        return DB::transaction(function () use ($ir, $data) {
            $ir->update([
                'status'       => $data['status'],
                'inspected_at' => now(),
                'inspector'    => $data['inspector'] ?? $ir->inspector,
                'outcome_note' => $data['outcome_note'] ?? null,
            ]);

            if ($ir->status === 'failed' && $ir->snag_id === null) {
                // raiseSnag emits `inspection.failed` itself, so this branch does not emit again —
                // one failed inspection, one event.
                $snag = $this->pack->raiseSnag($ir->project, [
                    'kind'        => 'defect',
                    'severity'    => 'major',
                    'title'       => __('project_management.site_records.failed_inspection_title', ['number' => $ir->number]),
                    'description' => $ir->outcome_note,
                    'location'    => $ir->element,
                ]);

                $ir->update(['snag_id' => $snag->id]);
            }

            if ($ir->status === 'passed') {
                $this->emitter->emit($ir->project, 'inspection.passed', [
                    'subject_type' => 'project',
                    'subject_id'   => $ir->project->uuid,
                    'source_ref'   => 'inspection:' . $ir->uuid,
                    'payload'      => ['inspector' => $ir->inspector ?: '—', 'reference' => $ir->number],
                ]);
            }

            return $ir->refresh();
        });
    }

    // ── Completion ──────────────────────────────────────────────────────────

    /**
     * Certify completion.
     *
     * Refuses practical completion while a critical defect or an open non-conformance is on the
     * register. That is not bureaucracy: practical completion releases retention and starts the
     * defects clock, and certifying it over an open NCR is signing away the leverage to have the
     * NCR fixed. Sectional and final completion are not blocked — sections are handed over with
     * work outstanding by design, and final completion comes after the defects period anyway.
     */
    public function certifyCompletion(Project $project, array $data): CompletionRecord
    {
        if (($data['kind'] ?? 'practical') === 'practical') {
            $blocking = Snag::where('project_id', $project->id)
                ->whereIn('status', ['open', 'in_progress', 'ready'])
                ->where(fn ($q) => $q->where('severity', 'critical')->orWhere('kind', 'ncr'))
                ->count();

            if ($blocking > 0) {
                throw new RuntimeException(__('project_management.site_records.completion_blocked', ['count' => $blocking]));
            }
        }

        return DB::transaction(function () use ($project, $data) {
            $record = CompletionRecord::create([
                ...$data,
                'project_id' => $project->id,
                'currency'   => $data['currency'] ?? $project->currency,
                'created_by' => Auth::id(),
            ]);

            /*
             * Retention released here moves from money held to money owed.
             *
             * Certifying completion is when it happens, and until it posts the retention liability
             * only ever accumulates — the balance sheet goes on saying the business is holding
             * money it paid out months ago. Posts nothing when nothing was released, and nothing
             * until the retention account is mapped.
             */
            app(ClaimLedgerPoster::class)->postRelease($record);

            $this->emitter->emit($project, 'milestone.verified', [
                'subject_type' => 'project',
                'subject_id'   => $project->uuid,
                'source_ref'   => 'completion:' . $record->uuid,
                'occurred_at'  => $record->certified_on->toIso8601String(),
                'payload'      => [
                    'milestone' => __('project_management.site_records.completion_milestone', [
                        'kind' => $record->kind,
                    ]),
                    'verified_by' => $record->certified_by ?: '—',
                ],
            ]);

            return $record;
        });
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * The next reference for this record type on this project.
     *
     * Same reasoning as the registers: SI-014 gets quoted in letters, so two of them is not a
     * cosmetic problem. Soft-deleted rows still hold their number.
     *
     * @param  class-string<Model>  $model
     */
    private function nextNumber(string $model, Project $project, string $prefix, string $column): string
    {
        return DB::transaction(function () use ($model, $project, $prefix, $column) {
            $last = $model::withTrashed()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value($column);

            $n = $last && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

            return sprintf('%s-%03d', $prefix, $n);
        });
    }
}
