<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCalibrationSession;
use App\Modules\PerformanceAppraisal\Models\PaCalibrationParticipant;
use App\Modules\PerformanceAppraisal\Models\PaApprovalWorkflow;
use App\Modules\PerformanceAppraisal\Models\PaApprovalRequest;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PaCalibrationService;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaCalibrationController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(
        private PaCalibrationService            $service,
        private PerformanceAppraisalPolicyGuard $policyGuard,
    ) {}

    public function index(): Response
    {
        $sessions = PaCalibrationSession::with('participants')
            ->orderByDesc('scheduled_date')
            ->get()
            ->map(function ($s) {
                $s->facilitator_name  = $this->resolveEmployeeName($s->facilitator_id);
                $s->participant_count = $s->participants->count();
                $s->finalized_count   = $s->participants->where('finalized', true)->count();
                unset($s->participants); // strip after aggregating
                return $s;
            });

        $workflows = PaApprovalWorkflow::with('steps')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($w) {
                $w->steps_count = $w->steps->count();
                return $w;
            });

        $requests = PaApprovalRequest::with('workflow')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($r) {
                $r->submitted_by_name = $this->resolveEmployeeName($r->submitted_by);
                $r->workflow_name     = $r->workflow?->name ?? '—';
                $r->progress_pct      = $r->progressPct();
                return $r;
            });

        $stats = [
            'sessions'        => $sessions->count(),
            'active_sessions' => $sessions->where('status', 'in_progress')->count(),
            'workflows'       => $workflows->where('is_active', true)->count(),
            'pending_approvals'=> $requests->whereIn('status', ['pending', 'in_progress'])->count(),
        ];

        return Inertia::render('Modules/PerformanceAppraisal/Calibration/Index', [
            'sessions'  => $sessions->values(),
            'workflows' => $workflows->values(),
            'requests'  => $requests->values(),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList(),
            'stats'     => $stats,
        ]);
    }

    // ── Calibration Sessions ──────────────────────────────────────────────────

    public function sessionStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cycle_id'       => 'required|integer',
            'title'          => 'required|string|max:255',
            'facilitator_id' => 'required|integer',
            'scheduled_date' => 'required|date',
            'notes'          => 'nullable|string',
        ]);
        PaCalibrationSession::create($data);
        return back();
    }

    public function sessionUpdate(Request $request, PaCalibrationSession $session): RedirectResponse
    {
        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'facilitator_id' => 'sometimes|integer',
            'scheduled_date' => 'sometimes|date',
            'status'         => 'sometimes|in:draft,in_progress,completed,cancelled',
            'notes'          => 'nullable|string',
        ]);
        $session->update($data);
        return back();
    }

    public function sessionDestroy(PaCalibrationSession $session): RedirectResponse
    {
        $session->delete();
        return back();
    }

    public function sessionFinalize(PaCalibrationSession $session): RedirectResponse
    {
        $this->policyGuard->forCalibrationFinalized($session, Auth::id());
        $this->service->finalizeSession($session);
        return back();
    }

    public function sessionSummary(PaCalibrationSession $session)
    {
        $session->load('participants');
        return response()->json([
            'summary'      => $this->service->sessionSummary($session),
            'participants' => $session->participants->map(fn($p) => array_merge($p->toArray(), [
                'employee_name' => $this->resolveEmployeeName($p->employee_id),
                'adjustment'    => $p->adjustment(),
            ])),
        ]);
    }

    // ── Calibration Participants ──────────────────────────────────────────────

    public function participantStore(Request $request, PaCalibrationSession $session): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'     => 'required|integer',
            'original_score'  => 'nullable|numeric|min:0|max:100',
            'calibrated_score'=> 'nullable|numeric|min:0|max:100',
        ]);

        $session->participants()->updateOrCreate(
            ['employee_id' => $data['employee_id']],
            array_merge($data, ['calibrator_id' => Auth::id()])
        );
        return back();
    }

    public function participantUpdate(Request $request, PaCalibrationParticipant $participant): RedirectResponse
    {
        $data = $request->validate([
            'calibrated_score'   => 'nullable|numeric|min:0|max:100',
            'adjustment_reason'  => 'nullable|string',
        ]);
        $participant->update(array_merge($data, ['calibrator_id' => Auth::id()]));
        return back();
    }

    public function participantDestroy(PaCalibrationParticipant $participant): RedirectResponse
    {
        $participant->delete();
        return back();
    }

    // ── Approval Workflows ────────────────────────────────────────────────────

    public function workflowStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'resource_type' => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'steps'         => 'array',
            'steps.*.step_label'   => 'required|string',
            'steps.*.approver_id'  => 'nullable|integer',
            'steps.*.approver_role'=> 'nullable|string',
            'steps.*.is_required'  => 'boolean',
        ]);

        $workflow = PaApprovalWorkflow::create([
            'name'          => $data['name'],
            'resource_type' => $data['resource_type'] ?? null,
            'description'   => $data['description']   ?? null,
        ]);

        foreach (($data['steps'] ?? []) as $idx => $step) {
            $workflow->steps()->create([
                'step_order'   => $idx + 1,
                'step_label'   => $step['step_label'],
                'approver_id'  => $step['approver_id']   ?? null,
                'approver_role'=> $step['approver_role'] ?? null,
                'is_required'  => $step['is_required']   ?? true,
            ]);
        }

        return back();
    }

    public function workflowToggle(PaApprovalWorkflow $workflow): RedirectResponse
    {
        $workflow->update(['is_active' => !$workflow->is_active]);
        return back();
    }

    public function workflowDestroy(PaApprovalWorkflow $workflow): RedirectResponse
    {
        $workflow->delete();
        return back();
    }

    // ── Approval Requests ─────────────────────────────────────────────────────

    public function requestStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workflow_id'   => 'required|exists:pa_approval_workflows,id',
            'resource_type' => 'required|string',
            'resource_id'   => 'required|integer',
            'title'         => 'required|string|max:255',
        ]);

        $this->service->submitRequest(
            (int)   $data['workflow_id'],
            $data['resource_type'],
            (int)   $data['resource_id'],
            $data['title'],
            Auth::id()
        );

        return back();
    }

    public function requestAction(Request $request, PaApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approved,rejected,recalled',
            'notes'  => 'nullable|string',
        ]);

        $this->policyGuard->forApprovalAction($approvalRequest, $data['action'], Auth::id());
        $this->service->processAction($approvalRequest, Auth::id(), $data['action'], $data['notes'] ?? null);
        return back();
    }

    public function requestDestroy(PaApprovalRequest $approvalRequest): RedirectResponse
    {
        $approvalRequest->delete();
        return back();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveEmployeeName(int $id): string
    {
        return $this->tenantPersonName($id);
    }

 /**

  * Everybody this tenant may choose between.

  *

  * Was built here, in two different shapes across these controllers — some through

  * `User`, which leans on a global scope that steps aside for a platform administrator,

  * and some as a raw `DB::table('users')` where no scope applied at all and `users`

  * carries no row-level security to catch it. One of them offered every tenant's people

  * to every other tenant; the ones reading `hr_people` offered almost nobody.

  */

 private function personList(): array

 {

     return $this->tenantPeopleOptions();

 }
}
