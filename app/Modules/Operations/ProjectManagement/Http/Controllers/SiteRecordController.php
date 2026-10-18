<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\CompletionRecord;
use App\Modules\Operations\ProjectManagement\Models\EquipmentInspection;
use App\Modules\Operations\ProjectManagement\Models\InspectionRequest;
use App\Modules\Operations\ProjectManagement\Models\MaterialTest;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\SiteInstruction;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\SiteRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The rest of the construction pack (§23).
 *
 * Kept on its own page rather than added as four more tabs to Site: the diary, bill and claims are
 * a *weekly* rhythm, and instructions, tests and inspections are a *daily* one. Somebody logging a
 * cube does not want to scroll past a payment certificate to do it.
 */
class SiteRecordController extends Controller
{
    public function __construct(private readonly SiteRecordService $records)
    {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $canCost = $request->user()->can('viewCosts', $project);

        return Inertia::render('Modules/ProjectManagement/Projects/SiteRecords', [
            'project' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name, 'currency' => $project->currency],

            /*
             * The people these records get signed by.
             *
             * Offered rather than imposed: every name on this page is stored as text, not as a
             * foreign key, because site records are routinely signed by people who have no login
             * here — the engineer who issued the instruction, the client's representative who
             * witnessed the test. Project members already model that (`user_id` is nullable for
             * exactly this reason), so the list covers the internal case and the field still accepts
             * a typed name for the rest. Typing every name by hand is how one foreman ends up in the
             * register under four spellings.
             */
            'people' => ProjectMember::where('project_id', $project->id)->active()
                ->orderBy('display_name')
                ->get(['uuid', 'display_name', 'role_key'])
                ->map(fn (ProjectMember $m) => [
                    'id'   => $m->uuid,
                    'name' => $m->display_name,
                    'role' => $m->role_key,
                ])->all(),

            'instructions' => SiteInstruction::where('project_id', $project->id)
                ->orderByDesc('issued_on')->orderByDesc('id')->get()
                ->map(fn (SiteInstruction $s) => [
                    'id'          => $s->uuid,
                    'number'      => $s->number,
                    'issued_on'   => $s->issued_on->toDateString(),
                    'issued_by'   => $s->issued_by,
                    'title'       => $s->title,
                    'instruction' => $s->instruction,
                    'variation'   => $s->claimed_as_variation,
                    // The gap where money goes missing: claimed as a variation, never raised as one.
                    'unconverted' => $s->isUnconvertedVariation(),
                    'change'      => $s->changeRequest?->code,
                    'status'      => $s->status,
                    'response'    => $s->response,
                ])->all(),

            'tests' => MaterialTest::where('project_id', $project->id)
                ->orderByDesc('sampled_on')->orderByDesc('id')->get()
                ->map(fn (MaterialTest $t) => [
                    'id'         => $t->uuid,
                    'reference'  => $t->reference,
                    'kind'       => $t->kind,
                    'element'    => $t->element,
                    'sampled_on' => $t->sampled_on->toDateString(),
                    'result_on'  => optional($t->result_on)->toDateString(),
                    'specified'  => $t->specified_value !== null ? (float) $t->specified_value : null,
                    'value'      => $t->result_value !== null ? (float) $t->result_value : null,
                    'unit'       => $t->unit,
                    'result'     => $t->result,
                    'lab'        => $t->lab,
                    'meets_spec' => $t->meetsSpecification(),
                ])->all(),

            'inspections' => InspectionRequest::where('project_id', $project->id)
                ->orderByDesc('requested_for')->get()
                ->map(fn (InspectionRequest $i) => [
                    'id'            => $i->uuid,
                    'number'        => $i->number,
                    'title'         => $i->title,
                    'element'       => $i->element,
                    'requested_for' => $i->requested_for->toIso8601String(),
                    'notice_hours'  => $i->noticeHours(),
                    'inspector'     => $i->inspector,
                    'status'        => $i->status,
                    'overdue'       => $i->isOverdue(),
                    'outcome_note'  => $i->outcome_note,
                ])->all(),

            'completions' => CompletionRecord::where('project_id', $project->id)
                ->orderBy('certified_on')->get()
                ->map(fn (CompletionRecord $c) => [
                    'id'           => $c->uuid,
                    'kind'         => $c->kind,
                    'section'      => $c->section,
                    'certified_on' => $c->certified_on->toDateString(),
                    'certified_by' => $c->certified_by,
                    'outstanding'  => $c->outstanding_work,
                    'defects_ends' => optional($c->defects_period_ends)->toDateString(),
                    'in_defects'   => $c->isInDefectsPeriod(),
                    'released'     => $canCost && $c->retention_released !== null ? (float) $c->retention_released : null,
                ])->all(),

            'equipment' => EquipmentInspection::where('project_id', $project->id)
                ->orderByDesc('inspected_on')->limit(100)->get()
                ->map(fn (EquipmentInspection $e) => [
                    'id'         => $e->uuid,
                    'equipment'  => $e->equipment,
                    'identifier' => $e->identifier,
                    'inspected'  => $e->inspected_on->toDateString(),
                    'inspector'  => $e->inspector,
                    'result'     => $e->result,
                    'defects'    => $e->defects,
                    'next_due'   => optional($e->next_due)->toDateString(),
                    'overdue'    => $e->isOverdue(),
                    'signed_by'  => $e->signed_by,
                ])->all(),

            'tasks' => Task::where('project_id', $project->id)->orderBy('name')->limit(400)
                ->get(['uuid', 'name'])->map(fn (Task $t) => ['id' => $t->uuid, 'name' => $t->name])->all(),

            'options' => [
                'test_kinds'         => MaterialTest::KINDS,
                'equipment_results'  => EquipmentInspection::RESULTS,
                'instruction_status' => SiteInstruction::STATUSES,
                'completion_kinds'   => CompletionRecord::KINDS,
            ],
            'can' => [
                'record' => $request->user()->can('update', $project),
                'costs'  => $canCost,
                // Certifying completion releases retention, so it sits with whoever may certify a
                // payment claim rather than with anybody who can edit the project.
                'certify' => $request->user()->can('certifyClaim', $project),
            ],
        ]);
    }

    // ── Site instructions ───────────────────────────────────────────────────

    public function storeInstruction(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->records->recordInstruction($project, $request->validate([
            'issued_on'   => ['required', 'date'],
            'issued_by'   => ['nullable', 'string', 'max:190'],
            'title'       => ['required', 'string', 'max:190'],
            'instruction' => ['required', 'string', 'max:5000'],
            'claimed_as_variation' => ['nullable', 'boolean'],
        ]));

        return back()->with('success', __('project_management.flash.instruction_recorded'));
    }

    public function updateInstruction(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->instruction($project, $uuid)->update($request->validate([
            'status'   => ['required', Rule::in(SiteInstruction::STATUSES)],
            'response' => ['nullable', 'string', 'max:5000'],
        ]));

        return back()->with('success', __('project_management.flash.instruction_updated'));
    }

    /** Turn an instruction claimed as a variation into a change request. */
    public function raiseVariation(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        try {
            $si = $this->records->raiseVariation($this->instruction($project, $uuid));
        } catch (RuntimeException $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.variation_raised', [
            'code' => $si->changeRequest?->code ?? '',
        ]));
    }

    // ── Material tests ──────────────────────────────────────────────────────

    public function storeTest(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->records->logTest($project, $request->validate([
            'kind'            => ['required', Rule::in(MaterialTest::KINDS)],
            'element'         => ['nullable', 'string', 'max:190'],
            'sampled_on'      => ['required', 'date', 'before_or_equal:today'],
            'specified_value' => ['nullable', 'numeric'],
            'unit'            => ['nullable', 'string', 'max:20'],
            'lab'             => ['nullable', 'string', 'max:190'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]));

        return back()->with('success', __('project_management.flash.test_logged'));
    }

    public function recordResult(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->records->recordResult(
            MaterialTest::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail(),
            $request->validate([
                'result'       => ['required', Rule::in(['pass', 'fail'])],
                'result_value' => ['nullable', 'numeric'],
                'result_on'    => ['nullable', 'date'],
                'lab'          => ['nullable', 'string', 'max:190'],
                'notes'        => ['nullable', 'string', 'max:2000'],
            ]),
        );

        return back()->with('success', __('project_management.flash.test_result_recorded'));
    }

    // ── Inspection requests ─────────────────────────────────────────────────

    public function storeInspection(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title'         => ['required', 'string', 'max:190'],
            'element'       => ['nullable', 'string', 'max:190'],
            'task_id'       => ['nullable', 'uuid'],
            'requested_for' => ['required', 'date', 'after:now'],
            'inspector'     => ['nullable', 'string', 'max:190'],
        ]);

        if (! empty($data['task_id'])) {
            $data['task_id'] = Task::where('project_id', $project->id)->where('uuid', $data['task_id'])->value('id');
        }

        $this->records->requestInspection($project, $data);

        return back()->with('success', __('project_management.flash.inspection_requested'));
    }

    public function recordInspection(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->records->recordInspection(
            InspectionRequest::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail(),
            $request->validate([
                'status'       => ['required', Rule::in(['passed', 'failed', 'cancelled'])],
                'inspector'    => ['nullable', 'string', 'max:190'],
                'outcome_note' => ['nullable', 'string', 'max:2000'],
            ]),
        );

        return back()->with('success', __('project_management.flash.inspection_recorded'));
    }

    // ── Completion ──────────────────────────────────────────────────────────

    public function certify(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('certifyClaim', $project);

        $data = $request->validate([
            'kind'                => ['required', Rule::in(CompletionRecord::KINDS)],
            'section'             => ['nullable', 'string', 'max:190'],
            'certified_on'        => ['required', 'date'],
            'certified_by'        => ['nullable', 'string', 'max:190'],
            'outstanding_work'    => ['nullable', 'string', 'max:5000'],
            'defects_period_days' => ['nullable', 'integer', 'between:0,3650'],
            'retention_released'  => ['nullable', 'numeric', 'min:0'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->records->certifyCompletion($project, $data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['kind' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.completion_certified'));
    }

    /**
     * Record a pre-use equipment check (§22).
     *
     * Signed the same way a document is: a typed name, the time and the address. It is the same
     * claim — this person says they checked this machine — and recording it any more loosely would
     * make the series worthless the first time it was questioned.
     */
    public function storeEquipmentInspection(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'equipment'    => ['required', 'string', 'max:190'],
            // Read off the machine, by scanner or by eye. Free text either way — a QR code that
            // does not match anything on file is still worth recording.
            'identifier'   => ['nullable', 'string', 'max:90'],
            'inspected_on' => ['required', 'date', 'before_or_equal:today'],
            'inspector'    => ['nullable', 'string', 'max:190'],
            'result'       => ['required', Rule::in(EquipmentInspection::RESULTS)],
            'defects'      => ['nullable', 'string', 'max:2000'],
            'next_due'     => ['nullable', 'date', 'after:inspected_on'],
            'signed_by'    => ['required', 'string', 'max:190'],
        ]);

        EquipmentInspection::create([
            ...$data,
            'project_id' => $project->id,
            'signed_at'  => now(),
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', __('project_management.flash.equipment_inspected'));
    }

    private function instruction(Project $project, string $uuid): SiteInstruction
    {
        return SiteInstruction::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }
}
