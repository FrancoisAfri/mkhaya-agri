<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\ApprovalEntityType;
use App\Modules\HR\Recruitment\Models\ApprovalDecision;
use App\Modules\HR\Recruitment\Models\ApprovalStage;
use App\Modules\HR\Recruitment\Models\ApprovalWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use App\Core\Role\Models\Role;

class ApprovalWorkflowController extends Controller
{
    public function index(): Response
    {
        $workflows = ApprovalWorkflow::with('stages')
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get()
            ->map(fn (ApprovalWorkflow $w) => [
                'id'          => $w->id,
                'uuid'        => $w->uuid,
                'name'        => $w->name,
                'description' => $w->description,
                'entity_type' => $w->entity_type,
                'is_active'   => $w->is_active,
                'stages'      => $w->stages->map(fn (ApprovalStage $s) => [
                    'id'               => $s->id,
                    'stage_number'     => $s->stage_number,
                    'name'             => $s->name,
                    'approver_role'    => $s->approver_role,
                    'approver_user_id' => $s->approver_user_id,
                    'is_required'      => $s->is_required,
                    'min_approvers'    => $s->min_approvers,
                ])->values(),
            ]);

        return Inertia::render('Modules/Recruitment/Settings/ApprovalWorkflows/Index', [
            'workflows'   => $workflows,
            'entityTypes' => ApprovalEntityType::options(),
            'roles'       => Role::orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($data, $request) {
            $workflow = ApprovalWorkflow::create([
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'entity_type' => $data['entity_type'],
                'is_active'   => $data['is_active'] ?? true,
                'created_by'  => $request->user()->id,
            ]);

            $this->syncStages($workflow, $data['stages'] ?? []);
        });

        return back()->with('success', 'Approval workflow created.');
    }

    public function update(Request $request, ApprovalWorkflow $approvalWorkflow): RedirectResponse
    {
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($data, $approvalWorkflow) {
            $approvalWorkflow->update([
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'entity_type' => $data['entity_type'],
                'is_active'   => $data['is_active'] ?? true,
            ]);

            $this->syncStages($approvalWorkflow, $data['stages'] ?? []);
        });

        return back()->with('success', 'Approval workflow updated.');
    }

    public function destroy(ApprovalWorkflow $approvalWorkflow): RedirectResponse
    {
        $hasDecisions = ApprovalDecision::where('workflow_id', $approvalWorkflow->id)->exists();
        if ($hasDecisions) {
            return back()->with('error', 'Cannot delete a workflow that has recorded decisions. Deactivate it instead.');
        }

        DB::transaction(function () use ($approvalWorkflow) {
            $approvalWorkflow->stages()->delete();
            $approvalWorkflow->delete();
        });

        return back()->with('success', 'Approval workflow deleted.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name'                        => ['required', 'string', 'max:150'],
            'description'                 => ['nullable', 'string', 'max:1000'],
            'entity_type'                 => ['required', 'string', 'in:' . implode(',', array_column(ApprovalEntityType::cases(), 'value'))],
            'is_active'                   => ['nullable', 'boolean'],
            'stages'                      => ['required', 'array', 'min:1'],
            'stages.*.name'               => ['required', 'string', 'max:120'],
            'stages.*.approver_role'      => ['nullable', 'string', 'max:120'],
            'stages.*.approver_user_id'   => ['nullable', 'integer', 'exists:users,id'],
            'stages.*.is_required'        => ['nullable', 'boolean'],
            'stages.*.min_approvers'      => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
    }

    private function syncStages(ApprovalWorkflow $workflow, array $stages): void
    {
        $workflow->stages()->delete();

        foreach (array_values($stages) as $index => $stage) {
            ApprovalStage::create([
                'workflow_id'      => $workflow->id,
                'stage_number'     => $index + 1,
                'name'             => $stage['name'],
                'approver_role'    => $stage['approver_role'] ?? null,
                'approver_user_id' => $stage['approver_user_id'] ?? null,
                'is_required'      => $stage['is_required'] ?? true,
                'min_approvers'    => $stage['min_approvers'] ?? 1,
            ]);
        }
    }
}
