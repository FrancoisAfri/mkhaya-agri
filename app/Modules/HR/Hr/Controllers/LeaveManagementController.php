<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Http\Controllers\Controller;
use App\Modules\HR\Leave\AI\LeaveAdvisorAI;
use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\Models\ApproverDelegation;
use App\Modules\HR\Leave\Models\PolicyRuleAuditLog;
use App\Modules\HR\Leave\Services\ApproverDelegationService;
use App\Modules\HR\Leave\Services\CoverageAnalysisService;
use App\Modules\HR\Leave\Services\PolicySyncService;
use App\Modules\HR\Leave\Services\WorkforceIntelligenceService;
use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveApprovalLevel;
use App\Modules\HR\Leave\Models\LeaveScheduledReport;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\ParentalLeavePool;
use App\Modules\HR\Leave\Requests\StoreLeaveRequestRequest;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveManualAllocationBatch;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use App\Modules\HR\Leave\Services\LeaveManualAllocationService;
use App\Modules\HR\Leave\Services\LeavePolicyGuard;
use App\Modules\HR\Leave\Services\LeaveRequestService;
use App\Modules\HR\Leave\Models\LeaveProfile;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use App\Modules\HR\Leave\Jobs\GenerateLeaveReportJob;
use App\Core\Auth\Services\AuthService;
use App\Core\SMS\Services\SmsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Inertia\Inertia;
use Inertia\Response;

class LeaveManagementController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService             $leaveRequestService,
        private readonly LeaveBalanceService             $balanceService,
        private readonly LeavePolicyGuard                $policyGuard,
        private readonly LeaveRequestRepositoryInterface $repo,
        private readonly LeaveAdvisorAI                  $aiAdvisorService,
        private readonly ApproverDelegationService       $delegationService,
        private readonly PolicySyncService               $policySyncService,
        private readonly CoverageAnalysisService         $coverageService,
        private readonly WorkforceIntelligenceService    $workforceService,
    ) {}

    // ── Dashboard ────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $hrPersonId = $this->currentHrPersonId();
        $cycle      = LeaveCycle::active();

        $recentRequests = $hrPersonId
            ? LeaveRequest::with(['leaveType'])
                ->where('employee_id', $hrPersonId)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn($r) => $this->formatRequest($r))
            : collect();

        $balances = $hrPersonId
            ? $this->enrichWithActualBalance($this->balanceService->getAllBalances($hrPersonId), $hrPersonId)->toArray()
            : [];

        $pendingApprovals = $this->repo->pendingForApprover(Auth::id())
            ->take(5)
            ->map(fn($r) => $this->formatRequest($r));

        /** @var \App\Core\User\Models\User|null $authUser */
        $authUser  = Auth::user();
        $isHrAdmin = $authUser?->hasRole(['super admin', 'administrator', 'HR']) ?? false;

        $summary = [
            'my_pending'          => LeaveRequest::where('employee_id', $hrPersonId)->pending()->count(),
            'my_approved_ytd'     => LeaveRequest::where('employee_id', $hrPersonId)->where('status', 'approved')->whereYear('start_date', now()->year)->count(),
            'pending_my_action'   => $this->repo->pendingForApprover(Auth::id())->count(),
            'open_anomalies'      => $hrPersonId ? LeaveAnomalyFlag::where('employee_id', $hrPersonId)->open()->count() : 0,
            'pending_delegations' => $isHrAdmin ? ApproverDelegation::where('approval_status', 'pending')->count() : 0,
        ];

        $pendingDelegationsList = $isHrAdmin
            ? ApproverDelegation::with(['delegator', 'delegate'])
                ->where('approval_status', 'pending')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($d) => [
                    'id'         => $d->id,
                    'delegator'  => $d->delegator?->name ?? '—',
                    'delegate'   => $d->delegate?->name  ?? '—',
                    'start_date' => $d->start_date->format('d M Y'),
                    'end_date'   => $d->end_date->format('d M Y'),
                    'reason'     => $d->reason,
                ])
                ->values()
            : collect();

        $leaveConfig              = LeaveConfiguration::first();
        $allowEmployeePayouts     = (bool) ($leaveConfig?->allow_employee_payout_requests ?? false);

        $myPayouts = $hrPersonId && $allowEmployeePayouts
            ? \App\Modules\HR\Leave\Models\LeavePayoutRecord::with('leaveType')
                ->where('employee_id', $hrPersonId)
                ->latest('payout_date')
                ->limit(20)
                ->get()
                ->map(fn($p) => [
                    'id'               => $p->id,
                    'leave_type'       => $p->leaveType?->name ?? '—',
                    'days_paid_out'    => round((float) $p->hours_paid_out / 8, 2),
                    'amount'           => (float) ($p->amount ?? 0),
                    'payout_date'      => $p->payout_date?->format('Y-m-d'),
                    'payroll_reference'=> $p->payroll_reference ?? '',
                    'status'           => $p->status,
                    'notes'            => $p->notes ?? '',
                ])
            : [];

        return Inertia::render('HR/Leave/Index', [
            'recentRequests'         => $recentRequests,
            'balances'               => $balances,
            'pendingApprovals'       => $pendingApprovals,
            'summary'                => $summary,
            'activeCycle'            => $cycle ? ['name' => $cycle->name, 'end_date' => $cycle->end_date->format('d M Y')] : null,
            'pendingDelegationsList' => $pendingDelegationsList,
            'allowEmployeePayouts'   => $allowEmployeePayouts,
            'myPayouts'              => $myPayouts,
        ]);
    }

    // ── Apply for Leave ───────────────────────────────────────────────────────

    public function apply(): Response
    {
        $leaveTypes = LeaveType::where('status', 1)->get(['id', 'name', 'statutory_type', 'is_paid', 'doc_required', 'notes_required', 'requires_medical_certificate']);
        $hrPersonId = $this->currentHrPersonId();
        $balances   = $hrPersonId ? $this->balanceService->getAllBalances($hrPersonId)->toArray() : [];
        $team       = $hrPersonId ? $this->teammateLeave($hrPersonId) : ['department' => null, 'leave' => []];

        return Inertia::render('HR/Leave/Apply', [
            'leaveTypes' => $leaveTypes,
            'balances'   => $balances,
            'employee'   => $hrPersonId ? DB::table('hr_people')->where('id', $hrPersonId)->first(['id', 'first_name', 'surname']) : null,
            'team'       => $team,
        ]);
    }

    /**
     * Who else in the applicant's department is already on (or booked for) leave over the coming
     * ~3 months — so they can see clashes before choosing dates. Team = same department
     * (company_units type=department); falls back to an empty set if the employee has none.
     *
     * @return array{department: ?string, leave: array<int, array<string,mixed>>}
     */
    private function teammateLeave(int $hrPersonId): array
    {
        $person = DB::table('hr_people')->where('id', $hrPersonId)->first(['id', 'user_id']);
        if (! $person || ! $person->user_id) {
            return ['department' => null, 'leave' => []];
        }

        // The applicant's department unit id(s).
        $deptUnitIds = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $person->user_id)->where('u.type', 'department')
            ->pluck('p.company_id');
        if ($deptUnitIds->isEmpty()) {
            return ['department' => null, 'leave' => []];
        }
        $departmentName = DB::table('company_units')->whereIn('id', $deptUnitIds)->value('name');

        // Other active people in the same department(s).
        $teammateUserIds = DB::table('company_unit_user_pivots')
            ->whereIn('company_id', $deptUnitIds)->where('user_id', '!=', $person->user_id)
            ->pluck('user_id')->unique();
        $teammates = DB::table('hr_people')->whereIn('user_id', $teammateUserIds)->where('status', 1)
            ->get(['id', 'first_name', 'surname']);
        if ($teammates->isEmpty()) {
            return ['department' => $departmentName, 'leave' => []];
        }
        $nameById = $teammates->mapWithKeys(fn ($t) => [$t->id => trim(($t->first_name ?? '') . ' ' . ($t->surname ?? ''))]);

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->addMonths(3)->endOfMonth()->toDateString();
        /*
         * The colour comes from the statutory type, not from a column.
         *
         * This asked for `leave_types.color`, which has never existed — no migration creates it
         * and no model declares it — so the page 500'd on "column color does not exist" the moment
         * anybody opened Apply. The two other places in this controller that need a colour already
         * derive it from `statutory_type` through leaveTypeColor(); this line was the outlier.
         *
         * Reading the real column also gives back what the fallback below could not: annual green,
         * sick red, parental purple, rather than one grey for everything.
         */
        $leave = LeaveRequest::with('leaveType:id,name,statutory_type')
            ->whereIn('employee_id', $teammates->pluck('id'))
            ->whereIn('status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'status'])
            ->map(fn ($lr) => [
                'name'       => $nameById[$lr->employee_id] ?? 'Teammate',
                'leave_type' => $lr->leaveType?->name,
                'color'      => $this->leaveTypeColor($lr->leaveType?->statutory_type),
                'start'      => \Illuminate\Support\Carbon::parse($lr->start_date)->toDateString(),
                'end'        => \Illuminate\Support\Carbon::parse($lr->end_date)->toDateString(),
                'status'     => is_object($lr->status) ? $lr->status->value : $lr->status,
            ])->values()->all();

        return ['department' => $departmentName, 'leave' => $leave];
    }

    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $validated = collect($request->validated())->except(['document', 'document_type'])->all();
        $dto     = SubmitLeaveRequestDTO::from($validated);
        $leaveReq = $this->leaveRequestService->submit($dto);

        // Attach an inline sick note / supporting document if one was uploaded with the form.
        if ($request->hasFile('document')) {
            $saved = (new \App\Modules\HR\Leave\Models\LeaveDocument())->saveFileToDatabase($request->file('document'));
            $document = $leaveReq->documents()->make([
                'uploader_id'       => Auth::id(),
                'document_type'     => $request->input('document_type', 'medical_certificate'),
                'original_filename' => $saved['original_name'],
                'disk'              => 'database',
                'file_size_bytes'   => $saved['size'],
                'mime_type'         => $saved['mime'],
                'is_encrypted'      => false,
                'uploaded_at'       => now(),
            ]);

            // The bytes go to the shared disk. `path` held the whole JSON envelope despite
            // its name, and is left alone until these rows are pruned.
            $document->writeBytes(base64_decode($saved['data']), pathinfo((string) $saved['original_name'], PATHINFO_EXTENSION));
            $document->save();
        }

        return redirect()->route('hr.leave.my-applications')
            ->with('success', "Leave request {$leaveReq->request_number} submitted successfully.");
    }

    /** Supervisor "Apply Leave for Staff" — pick an employee and lodge leave for them. */
    public function applyForStaff(): Response
    {
        $leaveTypes = LeaveType::where('status', 1)->get(['id', 'name', 'statutory_type', 'is_paid', 'doc_required', 'notes_required', 'requires_medical_certificate']);
        $myHrId     = $this->currentHrPersonId();
        $isAdmin    = (bool) (Auth::user()?->is_platform_admin || Auth::user()?->hasRole(['administrator', 'hr manager']));

        // Admins/HR see everyone; a supervisor sees who they manage + all non-login
        // employees (cleaners, who often have no manager set).
        $employees = DB::table('hr_people')->where('status', 1)
            ->when(! $isAdmin, fn ($q) => $q->where(function ($w) use ($myHrId) {
                $w->whereNull('user_id')
                  ->orWhere('manager_id', $myHrId)
                  ->orWhere('second_manager_id', $myHrId);
            }))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'surname', 'employee_number', 'user_id']);

        return Inertia::render('HR/Leave/ApplyForStaff', [
            'leaveTypes'   => $leaveTypes,
            'employees'    => $employees,
            'policy'       => (string) \App\Models\AppSetting::get('leave_onbehalf_approval_policy', 'hr'),
            'canSetPolicy' => $isAdmin,
        ]);
    }

    public function storeForStaff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'   => 'required|integer|exists:hr_people,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'request_mode'  => 'nullable|string',
            'notes'         => 'nullable|string|max:2000',
            'document'      => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'document_type' => 'nullable|in:medical_certificate,supporting_doc,birth_certificate,other',
        ]);

        // SA sick-note rule: block sick leave on a Mon/Fri or spanning 2+ days without a certificate.
        if (! $request->hasFile('document')) {
            $leaveType = LeaveType::find($data['leave_type_id']);
            if ($leaveType && $leaveType->statutory_type === 'sick') {
                $employee = \App\Modules\HR\Hr\Models\HRPerson::find($data['employee_id']);
                $check = $employee
                    ? app(\App\Modules\HR\Leave\Services\SickLeaveCertificateRule::class)
                        ->isCertificateRequired($employee, (string) $data['start_date'], (string) $data['end_date'])
                    : ['required' => false, 'reasons' => []];
                if ($check['required']) {
                    return back()->withInput()->withErrors([
                        'document' => 'A medical certificate is required. ' . implode(' ', $check['reasons']),
                    ]);
                }
            }
        }

        $dto = SubmitLeaveRequestDTO::from(array_merge(
            collect($data)->except(['document', 'document_type'])->all(),
            ['submitted_by' => Auth::id(), 'on_behalf' => true],
        ));
        $leaveReq = $this->leaveRequestService->submit($dto);

        if ($request->hasFile('document')) {
            $saved = (new \App\Modules\HR\Leave\Models\LeaveDocument())->saveFileToDatabase($request->file('document'));
            $document = $leaveReq->documents()->make([
                'uploader_id'       => Auth::id(),
                'document_type'     => $request->input('document_type', 'medical_certificate'),
                'original_filename' => $saved['original_name'],
                'disk'              => 'database',
                'file_size_bytes'   => $saved['size'],
                'mime_type'         => $saved['mime'],
                'is_encrypted'      => false,
                'uploaded_at'       => now(),
            ]);

            // The bytes go to the shared disk. `path` held the whole JSON envelope despite
            // its name, and is left alone until these rows are pruned.
            $document->writeBytes(base64_decode($saved['data']), pathinfo((string) $saved['original_name'], PATHINFO_EXTENSION));
            $document->save();
        }

        return redirect()->route('hr.leave.apply-for-staff')
            ->with('success', "Leave request {$leaveReq->request_number} submitted for the employee.");
    }

    /** JSON: teammates (same department) on leave for a given employee — powers the Apply-for-Staff calendar. */
    public function teammateLeaveApi(Request $request): \Illuminate\Http\JsonResponse
    {
        $employeeId = (int) $request->integer('employee_id');
        if (! $employeeId) {
            return response()->json(['department' => null, 'leave' => []]);
        }

        return response()->json($this->teammateLeave($employeeId));
    }

    /** Set the company's leave on-behalf approval routing policy. */
    public function updateOnBehalfPolicy(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()?->is_platform_admin || Auth::user()?->hasRole(['administrator', 'hr manager']), 403);

        $data = $request->validate(['policy' => 'required|in:hr,supervisor_manager,hr_then_final,auto']);
        \App\Models\AppSetting::set('leave_onbehalf_approval_policy', $data['policy']);

        return back()->with('success', 'Leave on-behalf approval routing updated.');
    }

    // ── My Applications ──────────────────────────────────────────────────────

    public function myApplications(Request $request): Response
    {
        $hrPersonId = $this->currentHrPersonId();

        $requests = $this->repo->forEmployee($hrPersonId, [
            'status' => $request->input('status'),
        ])->map(fn($r) => $this->formatRequest($r));

        return Inertia::render('HR/Leave/MyApplications', [
            'requests' => $requests,
            'filters'  => $request->only(['status']),
        ]);
    }

    public function show(LeaveRequest $leaveRequest): Response
    {
        $leaveRequest->load(['employee', 'leaveType', 'cycle', 'approvalLevels', 'documents']);

        $myHrPersonId = $this->currentHrPersonId();

        return Inertia::render('HR/Leave/Show', [
            'request'  => $this->formatRequestDetail($leaveRequest),
            'isOwner'  => $myHrPersonId !== null && $myHrPersonId === $leaveRequest->employee_id,
        ]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:1000']);

        $this->leaveRequestService->cancel($leaveRequest, $request->reason);

        return back()->with('success', "Leave request {$leaveRequest->request_number} cancelled.");
    }

    // ── Admin Leave Cancellation ──────────────────────────────────────────────

    public function adminCancelSearch(Request $request): Response
    {
        $q       = $request->input('q', '');
        $results = [];

        if ($q) {
            $results = LeaveRequest::with(['employee', 'leaveType'])
                ->whereIn('status', ['approved', 'pending', 'escalated'])
                ->where(function ($query) use ($q) {
                    $query->where('request_number', 'ilike', "%{$q}%")
                        ->orWhereHas('employee', fn($e) => $e->where(
                            \Illuminate\Support\Facades\DB::raw("TRIM(CONCAT(first_name, ' ', surname))"),
                            'ilike', "%{$q}%"
                        ))
                        ->orWhereHas('leaveType', fn($t) => $t->where('name', 'ilike', "%{$q}%"));
                })
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn($r) => [
                    'id'             => $r->id,
                    'request_number' => $r->request_number,
                    'employee_name'  => trim("{$r->employee?->first_name} {$r->employee?->surname}"),
                    'leave_type'     => $r->leaveType?->name,
                    'start_date'     => $r->start_date?->format('d M Y'),
                    'end_date'       => $r->end_date?->format('d M Y'),
                    'working_days'   => $r->working_days,
                    'status'         => $r->status instanceof \App\Modules\HR\Leave\Enums\LeaveRequestStatus
                        ? $r->status->value : $r->status,
                ])
                ->values();
        }

        return Inertia::render('HR/Leave/AdminCancelLeave', [
            'query'   => $q,
            'results' => $results,
        ]);
    }

    public function adminCancelLeave(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $this->leaveRequestService->cancel($leaveRequest, $request->reason, adminOverride: true);

        return back()->with('success', "Leave request {$leaveRequest->request_number} cancelled by admin.");
    }

    // POST /leave-management/apply/overtime
    public function storeOvertime(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $data         = $request->validated();
        $data['is_overtime'] = true;
        $dto          = SubmitLeaveRequestDTO::from($data);
        $leaveReq     = $this->leaveRequestService->submit($dto);

        return redirect()->route('hr.leave.my-applications')
            ->with('success', "Overtime application {$leaveReq->request_number} submitted successfully.");
    }

    // POST /leave-management/apply/bulk
    public function storeBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id'  => 'required|exists:leave_types,id',
            'dates'          => 'required|array|min:1|max:31',
            'dates.*'        => 'required|date_format:Y-m-d',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $hrPersonId = $this->currentHrPersonId();
        $submitted  = [];

        foreach ($data['dates'] as $date) {
            $dto = SubmitLeaveRequestDTO::from([
                'employee_id'   => $hrPersonId,
                'leave_type_id' => $data['leave_type_id'],
                'start_date'    => $date,
                'end_date'      => $date,
                'request_mode'  => 'daily',
                'notes'         => $data['notes'] ?? null,
                'is_bulk'       => true,
                'is_overtime'   => false,
            ]);

            $submitted[] = $this->leaveRequestService->submit($dto)->request_number;
        }

        return redirect()->route('hr.leave.my-applications')
            ->with('success', count($submitted) . ' bulk leave request(s) submitted: ' . implode(', ', $submitted));
    }

    // POST /leave-management/ai-advisor
    public function aiAdvisor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:1000',
            'context'  => 'nullable|array',
        ]);

        $hrPersonId = $this->currentHrPersonId() ?? 0;

        $answer = $this->aiAdvisorService->advise(
            $hrPersonId,
            $data['question'],
            $data['context'] ?? [],
        );

        return response()->json(['answer' => $answer]);
    }

    public function workingDaysPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $calculator = app(\App\Modules\HR\Leave\Services\WorkingDaysCalculator::class);
        $days       = $calculator->calculate($data['start_date'], $data['end_date']);

        return response()->json(['days' => $days]);
    }

    // ── Approvals ────────────────────────────────────────────────────────────

    public function approvals(): Response
    {
        $userId  = Auth::id();
        $config  = \App\Modules\HR\Leave\Models\LeaveConfiguration::first();
        $slaDays = (int) ($config?->number_of_days_until_escalation ?? 3);

        $pending = $this->repo->pendingForApprover($userId)
            ->map(function ($r) use ($slaDays) {
                $base       = $this->formatRequest($r);
                $daysWaiting = $r->submitted_at ? (int) now()->diffInDays($r->submitted_at) : 0;
                $slaLevel    = $r->approvalLevels()
                    ->where('level', $r->current_approval_level)
                    ->first();

                return array_merge($base, [
                    'days_waiting'    => $daysWaiting,
                    'sla_days'        => $slaDays,
                    'sla_due_at'      => $slaLevel?->sla_due_at?->format('d M Y'),
                    'sla_overdue'     => $slaLevel?->sla_due_at && $slaLevel->sla_due_at->isPast(),
                    'is_escalated'    => in_array($r->status?->value ?? $r->status, ['escalated']),
                    'escalation_count' => (int) $r->escalation_count,
                    'level_name'      => $r->current_approval_level
                        ? \App\Modules\HR\Leave\Models\LeaveApprovalLevel::levelName((int) $r->current_approval_level)
                        : null,
                    'bcea_has_errors' => ! empty($r->bcea_validation_result['errors']),
                ]);
            });

        $recentActioned = LeaveApprovalLevel::where('approver_id', $userId)
            ->whereIn('status', ['approved', 'rejected', 'skipped'])
            ->with(['leaveRequest.employee', 'leaveRequest.leaveType'])
            ->latest('actioned_at')
            ->limit(30)
            ->get()
            ->map(fn($l) => [
                'id'                 => $l->leaveRequest?->id,
                'request_number'     => $l->leaveRequest?->request_number,
                'employee'           => trim(($l->leaveRequest?->employee?->first_name ?? '') . ' ' . ($l->leaveRequest?->employee?->surname ?? '')),
                'leave_type'         => $l->leaveRequest?->leaveType?->name,
                'level_name'         => $l->level_name,
                'status'             => $l->status,
                'comment'            => $l->comment,
                'actioned_at'        => $l->actioned_at?->format('d M Y H:i'),
                'is_policy_override' => (bool) $l->is_policy_override,
            ]);

        // Active delegation for the current user
        $myDelegation = $this->delegationService->activeDelegation($userId);

        return Inertia::render('HR/Leave/Approvals', [
            'pending'        => $pending,
            'recentActioned' => $recentActioned,
            'summary'        => ['total_pending' => $pending->count()],
            'myDelegation'   => $myDelegation ? [
                'id'           => $myDelegation->id,
                'delegate'     => $myDelegation->delegate?->name,
                'start_date'   => $myDelegation->start_date->format('d M Y'),
                'end_date'     => $myDelegation->end_date->format('d M Y'),
                'reason'       => $myDelegation->reason,
            ] : null,
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate(['comment' => 'nullable|string|max:1000']);

        $this->leaveRequestService->approve($leaveRequest, $request->comment);

        return back()->with('success', "Leave request {$leaveRequest->request_number} approved.");
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:1000']);

        $this->leaveRequestService->reject($leaveRequest, $request->reason);

        return back()->with('success', "Leave request {$leaveRequest->request_number} rejected.");
    }

    public function policyOverride(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:1000']);

        $this->leaveRequestService->policyOverride($leaveRequest, Auth::id(), $request->reason);

        return back()->with('success', "Policy override applied. {$leaveRequest->request_number} approved.");
    }

    // ── Delegations ───────────────────────────────────────────────────────────

    public function delegations(): Response
    {
        $userId = Auth::id();
        /** @var \App\Core\User\Models\User|null $user */
        $user   = Auth::user();

        $active   = $this->delegationService->activeDelegation($userId);
        $auditLog = $this->delegationService->auditLog($userId)
            ->map(fn($d) => [
                'id'              => $d->id,
                'direction'       => $d->delegator_id === $userId ? 'outbound' : 'inbound',
                'other_party'     => $d->delegator_id === $userId
                    ? $d->delegate?->name
                    : $d->delegator?->name,
                'start_date'      => $d->start_date->format('d M Y'),
                'end_date'        => $d->end_date->format('d M Y'),
                'reason'          => $d->reason,
                'is_active'       => $d->is_active,
                'approval_status' => $d->approval_status ?? 'approved',
                'decline_reason'  => $d->decline_reason,
                'revoked_at'      => $d->revoked_at?->format('d M Y H:i'),
            ]);

        // HR/admin can see all pending delegations awaiting approval
        $pendingDelegations = [];
        $canManageDelegations = $user !== null && $user->hasRole(['super admin', 'administrator', 'HR']);
        if ($canManageDelegations) {
            $pendingDelegations = ApproverDelegation::with(['delegator', 'delegate'])
                ->where('approval_status', 'pending')
                ->latest()
                ->get()
                ->map(fn($d) => [
                    'id'         => $d->id,
                    'delegator'  => $d->delegator?->name,
                    'delegate'   => $d->delegate?->name,
                    'start_date' => $d->start_date->format('d M Y'),
                    'end_date'   => $d->end_date->format('d M Y'),
                    'reason'     => $d->reason,
                ])
                ->values();
        }

        // All users who can receive a delegation (other than self)
        $users = DB::table('users')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.id', '!=', $userId)
            ->where('hr_people.status', 1)
            ->orderByRaw("TRIM(CONCAT(hr_people.first_name, ' ', hr_people.surname))")
            ->get([
                'users.id',
                'users.email',
                DB::raw("TRIM(CONCAT(hr_people.first_name, ' ', hr_people.surname)) as name"),
            ])
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        return Inertia::render('HR/Leave/Delegations', [
            'activeDelegation' => $active ? [
                'id'              => $active->id,
                'delegate'        => $active->delegate?->name,
                'start_date'      => $active->start_date->format('d M Y'),
                'end_date'        => $active->end_date->format('d M Y'),
                'reason'          => $active->reason,
                'approval_status' => $active->approval_status ?? 'approved',
            ] : null,
            'auditLog'            => $auditLog,
            'users'               => $users,
            'pendingDelegations'  => $pendingDelegations,
            'canManageDelegations'=> $canManageDelegations,
            'allowDelegation'     => (bool) (LeaveConfiguration::first()?->allow_delegation ?? true),
        ]);
    }

    public function storeDelegation(Request $request): RedirectResponse
    {
        $delegationConfig = LeaveConfiguration::first();
        if ($delegationConfig !== null && ! $delegationConfig->allow_delegation) {
            return back()->withErrors(['delegation' => 'Approval delegation is currently disabled by configuration.']);
        }

        $data = $request->validate([
            'delegate_id' => 'required|exists:users,id|different:' . Auth::id(),
            'start_date'  => 'required|date|after_or_equal:today',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'nullable|string|max:500',
        ]);

        $delegation = $this->delegationService->delegate(
            Auth::id(),
            (int) $data['delegate_id'],
            $data['start_date'],
            $data['end_date'],
            $data['reason'] ?? null,
            Auth::id(),
        );

        // If pending approval, notify HR/admin users
        if (($delegation->approval_status ?? 'approved') === 'pending') {
            $delegatorName = Auth::user()?->name ?? 'An employee';
            $delegateName  = $delegation->delegate?->name ?? 'a colleague';
            $hrAdmins = \App\Core\User\Models\User::role(['super admin', 'administrator', 'HR'])->get();
            foreach ($hrAdmins as $admin) {
                $this->notifyUserSilently(
                    $admin,
                    'Delegation Awaiting Approval',
                    "{$delegatorName} has requested to delegate their approval authority to {$delegateName} ({$data['start_date']} – {$data['end_date']}). Your approval is required.",
                    '/leave-management/delegations'
                );
            }
        }

        return back()->with('success', 'Delegation created successfully.');
    }

    public function revokeDelegation(ApproverDelegation $delegation): RedirectResponse
    {
        abort_unless($delegation->delegator_id === Auth::id(), 403);

        $this->delegationService->revoke($delegation->id, Auth::id());

        return back()->with('success', 'Delegation revoked.');
    }

    public function approveDelegation(ApproverDelegation $delegation): RedirectResponse
    {
        /** @var \App\Core\User\Models\User|null $user */
        $user = Auth::user();
        abort_unless($user?->hasRole(['super admin', 'administrator', 'HR']), 403);

        $delegation->update(['is_active' => true, 'approval_status' => 'approved']);

        // Notify the delegator their delegation was approved
        $this->notifyUserSilently(
            $delegation->delegator,
            'Delegation Approved',
            "Your delegation to {$delegation->delegate?->name} ({$delegation->start_date->format('d M Y')} – {$delegation->end_date->format('d M Y')}) has been approved.",
            '/leave-management/delegations'
        );
        // Notify the delegate they now have delegated authority
        $this->notifyUserSilently(
            $delegation->delegate,
            'Delegation Assigned',
            "{$delegation->delegator?->name} has delegated their approval authority to you ({$delegation->start_date->format('d M Y')} – {$delegation->end_date->format('d M Y')}).",
            '/leave-management/delegations'
        );

        return back()->with('success', 'Delegation approved.');
    }

    public function declineDelegation(Request $request, ApproverDelegation $delegation): RedirectResponse
    {
        /** @var \App\Core\User\Models\User|null $user */
        $user = Auth::user();
        abort_unless($user?->hasRole(['super admin', 'administrator', 'HR']), 403);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $delegation->update([
            'is_active'       => false,
            'approval_status' => 'declined',
            'decline_reason'  => $data['reason'],
        ]);

        // Notify delegator their delegation was declined
        $this->notifyUserSilently(
            $delegation->delegator,
            'Delegation Declined',
            "Your delegation to {$delegation->delegate?->name} was declined. Reason: {$data['reason']}",
            '/leave-management/delegations'
        );

        return back()->with('success', 'Delegation declined.');
    }

    private function notifyUserSilently(
        ?\App\Core\User\Models\User $user,
        string $title,
        string $message,
        string $url = '/'
    ): void {
        if (! $user) return;
        try {
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'type'            => 'App\\Notifications\\DelegationNotification',
                'notifiable_type' => 'App\\Core\\User\\Models\\User',
                'notifiable_id'   => $user->id,
                'data'            => json_encode(['type' => 'delegation', 'title' => $title, 'message' => $message, 'url' => $url]),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Delegation notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Balances ─────────────────────────────────────────────────────────────

    public function balances(): Response
    {
        $hrPersonId = $this->currentHrPersonId();
        $cycle      = LeaveCycle::active();
        $balances   = $hrPersonId
            ? $this->enrichWithActualBalance($this->balanceService->getAllBalances($hrPersonId), $hrPersonId)
            : collect();

        $transactions = [];
        $breakdowns   = [];

        foreach ($balances as $b) {
            $leaveTypeId = $b['leave_type_id'];

            $transactions[$leaveTypeId] = $this->balanceService
                ->getTransactionHistory($hrPersonId, $leaveTypeId)
                ->map(fn($t) => [
                    'date'        => $t->created_at?->format('d M Y'),
                    'type'        => is_object($t->transaction_type) ? $t->transaction_type->label() : ucfirst(str_replace('_', ' ', (string) $t->transaction_type)),
                    'hours'       => (float) $t->hours,
                    'balance'     => (float) $t->balance_after,
                    'description' => $t->description,
                ])
                ->values();

            $breakdowns[$leaveTypeId] = $this->balanceService->getAllocationBreakdown($hrPersonId, $leaveTypeId, $cycle?->id);
        }

        // Cycle progress (0–100)
        $cycleProgress = null;
        if ($cycle) {
            $start    = $cycle->start_date->startOfDay();
            $end      = $cycle->end_date->endOfDay();
            $total    = $start->diffInDays($end) ?: 1;
            $elapsed  = min($start->diffInDays(now()), $total);
            $cycleProgress = (int) round(($elapsed / $total) * 100);
        }

        $hasNegative = $balances->contains(fn($b) => (float) $b['balance_hours'] < 0);

        return Inertia::render('HR/Leave/Balances', [
            'balances'      => $balances->values(),
            'transactions'  => $transactions,
            'breakdowns'    => $breakdowns,
            'cycle'         => $cycle ? [
                'name'       => $cycle->name,
                'start_date' => $cycle->start_date->format('d M Y'),
                'end_date'   => $cycle->end_date->format('d M Y'),
                'progress'   => $cycleProgress,
            ] : null,
            'hasNegative'   => $hasNegative,
        ]);
    }

    // ── Calendar ─────────────────────────────────────────────────────────────

    public function calendar(Request $request): Response
    {
        $user = Auth::user();

        // ── Role-based access ────────────────────────────────────────────────
        $isViewAll   = $user?->hasRole(['executive', 'HR', 'super admin', 'administrator']) ?? false;
        $isSeniorMgr = $user?->hasRole(['senior manager']) ?? false;
        $isManager   = $user?->hasRole(['team leader', 'supervisor', 'manager', 'compliance officer', 'senior']) ?? false;

        // Regular 'user' role (and any unrecognised role) cannot access the calendar
        if (! $isViewAll && ! $isSeniorMgr && ! $isManager) {
            abort(403, 'You do not have permission to view the Leave Calendar.');
        }

        $startDate = now()->startOfMonth()->format('Y-m-d');
        $endDate   = now()->addMonths(2)->endOfMonth()->format('Y-m-d');
        $teamView  = $request->boolean('team_view', false);
        $divIds    = array_values(array_filter(array_map('intval', (array) $request->input('division_ids', []))));

        // ── Determine which employee IDs this user may see ───────────────────
        $myPersonId       = DB::table('hr_people')->where('user_id', Auth::id())->value('id');
        $allowedPersonIds = null; // null = unrestricted (HR / admin / executive)

        if (! $isViewAll) {
            $directReports = $myPersonId
                ? DB::table('hr_people')->where('manager_id', $myPersonId)->pluck('id')->toArray()
                : [];

            if ($isSeniorMgr && ! empty($directReports)) {
                // Two levels deep: direct reports + their direct reports
                $level2 = DB::table('hr_people')
                    ->whereIn('manager_id', $directReports)
                    ->pluck('id')
                    ->toArray();
                $allowedPersonIds = array_values(array_unique(array_merge($directReports, $level2)));
            } else {
                $allowedPersonIds = $directReports;
            }
        }

        $leaveTypes = LeaveType::where('status', 1)
            ->get(['id', 'name', 'statutory_type'])
            ->map(fn($t) => [
                'id'    => $t->id,
                'name'  => $t->name,
                'color' => $this->leaveTypeColor($t->statutory_type),
            ]);

        $colorById = $leaveTypes->pluck('color', 'id');

        $events = LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('end_date', '>=', $startDate)
            ->where('start_date', '<=', $endDate)
            ->when($allowedPersonIds !== null, fn($q) => $q->whereIn('employee_id', $allowedPersonIds ?: [0]))
            ->when(!empty($divIds), fn($q) => $q->whereHas('employee', fn($e) => $e->whereIn('division_level_1', $divIds)))
            ->when($isViewAll && $teamView, function ($q) use ($myPersonId) {
                // HR/admin can toggle to see only their own team
                $ids = $myPersonId
                    ? DB::table('hr_people')->where('manager_id', $myPersonId)->pluck('id')->toArray()
                    : [];
                $q->whereIn('employee_id', $ids ?: [0]);
            })
            ->get()
            ->map(fn($r) => [
                'id'            => $r->id,
                'employee_id'   => $r->employee_id,
                'employee_name' => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')) ?: "Employee #{$r->employee_id}",
                'leave_type'    => $r->leaveType?->name ?? 'Unknown',
                'leave_type_id' => $r->leave_type_id,
                'start'         => $r->start_date->format('Y-m-d'),
                'end'           => $r->end_date->addDay()->format('Y-m-d'),
                'color'         => $colorById[$r->leave_type_id] ?? $this->leaveTypeColor($r->leaveType?->statutory_type),
                'working_days'  => $r->working_days,
                'status'        => $r->status?->value ?? (string) $r->status,
                'is_pending'    => in_array($r->status?->value ?? $r->status, ['pending_manager', 'pending_hr']),
            ]);

        $heatmap          = $this->coverageService->getDailyCoverage($startDate, $endDate, $divIds[0] ?? null, $allowedPersonIds, $isViewAll);
        $clashes          = $this->coverageService->getClashAlerts($startDate, $endDate, $allowedPersonIds);
        $criticalWarnings = $this->coverageService->getCriticalRoleWarnings($startDate, $endDate, $allowedPersonIds);
        $divisions        = $this->coverageService->getDivisions(null, $allowedPersonIds, $isViewAll);

        // Determine the calendar access level to send to the frontend
        $calendarRole = match (true) {
            $isViewAll   => 'all',
            $isSeniorMgr => 'senior_manager',
            default      => 'manager',
        };

        return Inertia::render('HR/Leave/Calendar', [
            'events'            => $events,
            'leaveTypes'        => $leaveTypes->values(),
            'heatmap'           => $heatmap,
            'clashes'           => $clashes,
            'criticalWarnings'  => $criticalWarnings,
            'divisions'         => $divisions,
            'selectedDivisions' => $divIds,
            'teamView'          => $teamView,
            'calendarRole'      => $calendarRole,
            'dateRange'         => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    public function workforceDashboard(): Response
    {
        /** @var \App\Core\User\Models\User|null $user */
        $user = Auth::user();

        // Plain 'user' role has no access to workforce planning data
        if (! $user || $user->hasRole(['user'])) {
            abort(403, 'Access denied.');
        }

        // Determine which hr_people IDs this user may see.
        // null means unrestricted (see everyone).
        $employeeIds = null;

        $canSeeAll = $user->hasRole(['executive', 'HR', 'super admin', 'administrator']);

        if (! $canSeeAll) {
            $hrPersonId = DB::table('hr_people')->where('user_id', $user->id)->value('id');

            if ($user->hasRole(['senior manager'])) {
                // Direct reports + their direct reports (2 levels)
                $directIds = $hrPersonId
                    ? DB::table('hr_people')->where('manager_id', $hrPersonId)->pluck('id')->toArray()
                    : [];
                $secondIds = ! empty($directIds)
                    ? DB::table('hr_people')->whereIn('manager_id', $directIds)->pluck('id')->toArray()
                    : [];
                $employeeIds = array_values(array_unique(array_merge($directIds, $secondIds)));
            } else {
                // team leader / supervisor / manager / compliance officer / senior → direct reports only
                $employeeIds = $hrPersonId
                    ? DB::table('hr_people')->where('manager_id', $hrPersonId)->pluck('id')->toArray()
                    : [];
            }
        }

        $startDate = now()->format('Y-m-d');
        $endDate   = now()->addDays(30)->format('Y-m-d');
        $today     = $startDate;

        $heatmap   = $this->coverageService->getDailyCoverage($startDate, $endDate, null, $employeeIds, $canSeeAll);
        $clashes   = $this->coverageService->getClashAlerts($startDate, $endDate, $employeeIds);
        $warnings  = $this->coverageService->getCriticalRoleWarnings($startDate, $endDate, $employeeIds);
        $divisions = $this->coverageService->getDivisions(null, $employeeIds, $canSeeAll);
        $pressure  = $this->workforceService->analyseDepartmentPressure(
            $divisions[0]['id'] ?? 1
        );

        $leaveQuery   = DB::table('leave_requests')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today);
        $pendingQuery = DB::table('leave_requests')->whereIn('status', ['pending_manager', 'pending_hr']);
        $headcountQ   = DB::table('hr_people')->where('status', 1);

        if ($employeeIds !== null) {
            $leaveQuery->whereIn('employee_id', $employeeIds);
            $pendingQuery->whereIn('employee_id', $employeeIds);
            $headcountQ->whereIn('id', $employeeIds);
        }

        $onLeave     = $leaveQuery->count();
        $pending     = $pendingQuery->count();
        $headcount   = $headcountQ->count();
        $coveragePct = $headcount > 0 ? max(0, round(($headcount - $onLeave) / $headcount * 100)) : 100;

        return Inertia::render('HR/Leave/WorkforceDashboard', [
            'heatmap'          => $heatmap,
            'clashes'          => $clashes,
            'criticalWarnings' => $warnings,
            'divisions'        => $divisions,
            'pressure'         => $pressure,
            'stats' => [
                'headcount'    => $headcount,
                'on_leave'     => $onLeave,
                'pending'      => $pending,
                'coverage_pct' => $coveragePct,
            ],
            'dateRange' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    public function alternativeDates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'    => 'required|integer',
            'preferred_start' => 'required|date',
            'preferred_end'   => 'required|date|after_or_equal:preferred_start',
            'working_days'   => 'required|integer|min:1',
        ]);

        $suggestions = $this->coverageService->suggestAlternativeDates(
            $validated['employee_id'],
            $validated['preferred_start'],
            $validated['preferred_end'],
            $validated['working_days'],
        );

        $aiNarrative = count($suggestions) > 0
            ? $this->workforceService->predictLeaveClash(0, $validated['preferred_start'], $validated['preferred_end'])['ai_insight'] ?? null
            : null;

        return response()->json([
            'suggestions'  => $suggestions,
            'ai_narrative' => $aiNarrative,
        ]);
    }

    // ── Manager Dashboard ────────────────────────────────────────────────────

    public function managerDashboard(): Response
    {
        $managerId = Auth::id();
        $today     = now()->format('Y-m-d');
        $monthEnd  = now()->endOfMonth()->format('Y-m-d');

        // Team members managed by this user (hr_people.manager_id → hr_people.user_id)
        $teamIds = DB::table('hr_people')
            ->join('hr_people as mgr', 'mgr.id', '=', 'hr_people.manager_id')
            ->where('mgr.user_id', $managerId)
            ->pluck('hr_people.id')
            ->toArray();

        // When the logged-in user has no explicit team (e.g. admin / super-user),
        // show company-wide data so the dashboard is always useful.
        $noTeam = empty($teamIds);

        $teamOnLeaveToday = LeaveRequest::with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date',   '>=', $today)
            ->when(! $noTeam, fn($q) => $q->whereIn('employee_id', $teamIds))
            ->get()
            ->map(fn($r) => [
                'employee_name' => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                'leave_type'    => $r->leaveType?->name ?? 'Unknown',
                'end_date'      => $r->end_date?->format('d M Y'),
                'working_days'  => $r->working_days,
            ]);

        $pendingApprovals = $this->repo->pendingForApprover($managerId)
            ->map(fn($r) => $this->formatRequest($r));

        // If no team is found via manager_id, show all pending requests
        if ($noTeam && $pendingApprovals->isEmpty()) {
            $pendingApprovals = LeaveRequest::with(['employee', 'leaveType'])
                ->whereIn('status', ['pending_manager', 'pending_hr'])
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn($r) => $this->formatRequest($r));
        }

        $upcomingLeave = LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('status', ['approved', 'pending', 'pending_manager', 'pending_department', 'pending_hr', 'pending_payroll'])
            ->where('start_date', '>=', $today)
            ->where('start_date', '<=', $monthEnd)
            ->when(! $noTeam, fn($q) => $q->whereIn('employee_id', $teamIds))
            ->orderBy('start_date')
            ->get()
            ->map(fn($r) => [
                'employee_name' => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                'leave_type'    => $r->leaveType?->name ?? 'Unknown',
                'start_date'    => $r->start_date?->format('d M Y'),
                'end_date'      => $r->end_date?->format('d M Y'),
                'working_days'  => $r->working_days,
                'status'        => $r->status?->value ?? (string) $r->status,
            ]);

        $teamAnomalies = LeaveAnomalyFlag::with('employee')
            ->where('false_positive', false)
            ->when(! $noTeam, fn($q) => $q->whereIn('employee_id', $teamIds))
            ->latest('detected_at')
            ->limit(20)
            ->get()
            ->map(fn($f) => [
                'employee_name' => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                'anomaly_type'  => $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : (string) $f->anomaly_type,
                'severity'      => $f->severity ?? 'medium',
                'detected_at'   => $f->detected_at?->format('d M Y'),
            ]);

        $balanceQuery = DB::table('leave_balance_transactions as lbt')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->select('lbt.employee_id', DB::raw("TRIM(CONCAT(hp.first_name,' ',hp.surname)) as employee_name"), 'lt.name as leave_type', DB::raw('MAX(lbt.id) as latest_tx_id'))
            ->groupBy('lbt.employee_id', 'hp.first_name', 'hp.surname', 'lt.name');

        if (! $noTeam) {
            $balanceQuery->whereIn('lbt.employee_id', $teamIds);
        }

        $teamBalances = $balanceQuery->get()->map(function ($row) {
            $bal = DB::table('leave_balance_transactions')->where('id', $row->latest_tx_id)->value('balance_after') ?? 0;
            return [
                'employee_name' => $row->employee_name,
                'leave_type'    => $row->leave_type,
                'balance_days'  => round((float) $bal / 8, 1),
                'is_negative'   => (float) $bal < 0,
            ];
        });

        $headcount = $noTeam
            ? DB::table('hr_people')->where('status', 1)->count()
            : count($teamIds);

        $stats = [
            'team_size'        => $headcount,
            'on_leave_today'   => $teamOnLeaveToday->count(),
            'pending_approvals'=> $pendingApprovals->count(),
            'anomaly_flags'    => $teamAnomalies->count(),
        ];

        return Inertia::render('HR/Leave/ManagerDashboard', [
            'stats'             => $stats,
            'teamOnLeaveToday'  => $teamOnLeaveToday->values(),
            'pendingApprovals'  => $pendingApprovals->values(),
            'upcomingLeave'     => $upcomingLeave->values(),
            'teamAnomalies'     => $teamAnomalies->values(),
            'teamBalances'      => $teamBalances->values(),
        ]);
    }

    // ── Parental Leave ───────────────────────────────────────────────────────

    public function parentalLeave(): Response
    {
        $hrPersonId = $this->currentHrPersonId();
        /** @var \App\Core\User\Models\User|null $user */
        $user      = Auth::user();
        $canManage = $user?->hasRole(['administrator', 'super admin', 'HR', 'hr_manager']) ?? false;

        // HR managers see all pools; employees only see their own
        $pools = $canManage
            ? ParentalLeavePool::with(['primaryParent', 'secondaryParent'])->get()
            : ($hrPersonId
                ? ParentalLeavePool::with(['primaryParent', 'secondaryParent'])
                    ->where(fn($q) => $q->where('primary_parent_id', $hrPersonId)->orWhere('secondary_parent_id', $hrPersonId))
                    ->get()
                : collect());

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->orderBy('first_name')
            ->get(['id', DB::raw("TRIM(CONCAT(first_name,' ',surname)) as full_name")])
            ->map(fn($e) => ['id' => $e->id, 'full_name' => $e->full_name]);

        $formattedPools = $pools->map(fn($p) => [
            'id'                    => $p->id,
            'primary_parent_name'   => trim(($p->primaryParent?->first_name ?? '') . ' ' . ($p->primaryParent?->surname ?? '')) ?: '—',
            'secondary_parent_name' => $p->secondaryParent
                ? trim(($p->secondaryParent->first_name ?? '') . ' ' . ($p->secondaryParent->surname ?? ''))
                : null,
            'child_dob'             => $p->expected_birth_date?->format('d M Y'),
            'total_pool_hours'      => (float) $p->total_pool_hours,
            'primary_hours_used'    => (float) ($p->primary_used_hours ?? 0),
            'secondary_hours_used'  => (float) ($p->secondary_used_hours ?? 0),
            'primary_lock_until'    => $p->primary_lock_until?->format('Y-m-d'),
            'status'                => $p->status,
            'legislation_version'   => $p->legislation_version,
            'uif_registered'        => (bool) $p->uif_eligible,
            'uif_reference'         => $p->uif_reference,
            'approved_by_name'      => $p->approved_by
                ? DB::table('hr_people')->join('users', 'users.id', '=', 'hr_people.user_id')->where('users.id', $p->approved_by)->value(DB::raw("TRIM(CONCAT(hr_people.first_name,' ',hr_people.surname))"))
                : null,
            'approved_at'           => $p->updated_at?->format('d M Y'),
        ]);

        return Inertia::render('HR/Leave/ParentalLeave', [
            'pools'      => $formattedPools,
            'hrPersonId' => $hrPersonId,
            'canManage'  => $canManage,
            'employees'  => $employees,
        ]);
    }

    public function approveParentalPool(\App\Modules\HR\Leave\Models\ParentalLeavePool $pool): RedirectResponse
    {
        /** @var \App\Core\User\Models\User|null $user */
        $user = Auth::user();
        if (! $user?->hasRole(['administrator', 'super admin', 'HR', 'hr_manager'])) {
            abort(403);
        }

        $pool->update([
            'status'      => 'active',
            'approved_by' => Auth::id(),
        ]);

        return back()->with('success', 'Parental leave pool approved.');
    }

    public function storeParentalLeave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'primary_parent_id'    => 'required|exists:hr_people,id',
            'secondary_parent_id'  => 'nullable|exists:hr_people,id|different:primary_parent_id',
            'child_dob'            => 'required|date',
            'primary_weeks'        => 'required|integer|min:6|max:52',
            'secondary_weeks'      => 'nullable|integer|min:0|max:52',
            'legislation_version'  => 'nullable|string',
            'uif_registered'       => 'boolean',
            'uif_reference'        => 'nullable|string|max:100',
        ]);

        $primaryHours   = (int) $data['primary_weeks'] * 40;
        $secondaryHours = isset($data['secondary_weeks']) ? (int) $data['secondary_weeks'] * 40 : 0;

        ParentalLeavePool::create([
            'primary_parent_id'          => $data['primary_parent_id'],
            'secondary_parent_id'        => $data['secondary_parent_id'] ?? null,
            'expected_birth_date'        => $data['child_dob'],
            'pool_type'                  => 'birth',
            'primary_allocated_hours'    => $primaryHours,
            'secondary_allocated_hours'  => $secondaryHours,
            'total_pool_hours'           => $primaryHours + $secondaryHours,
            'concurrent_leave_allowed'   => false,
            'uif_eligible'               => $data['uif_registered'] ?? false,
            'uif_reference'              => $data['uif_reference'] ?? null,
            'status'                     => 'pending',
            'legislation_version'        => $data['legislation_version'] ?? 'VanWyk2025',
            'approved_by'                => Auth::id(),
        ]);

        return back()->with('success', 'Parental leave pool created successfully.');
    }

    // ── Reports ──────────────────────────────────────────────────────────────

    // ── Employee Payout Self-Service ──────────────────────────────────────────

    public function storeEmployeePayout(Request $request): RedirectResponse
    {
        $config = LeaveConfiguration::first();
        if (!($config?->allow_employee_payout_requests)) {
            abort(403, 'Leave payout requests are not enabled.');
        }

        $hrPersonId = $this->currentHrPersonId();
        if (!$hrPersonId) {
            abort(403, 'No employee record linked to your account.');
        }

        $data = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'days'          => 'required|numeric|min:0.5|max:30',
            'notes'         => 'nullable|string|max:500',
        ]);

        $hours   = round($data['days'] * 8, 2);
        $balance = $this->balanceService->getBalance($hrPersonId, $data['leave_type_id']);

        if ($balance < $hours) {
            return back()->withErrors([
                'days' => "Insufficient balance. Your current balance is " . round($balance / 8, 2) . " day(s).",
            ]);
        }

        // Minimum remaining balance: at least 5 days must remain after payout
        $minRemainingHours = 40;
        if (($balance - $hours) < $minRemainingHours) {
            return back()->withErrors([
                'days' => "You must retain at least 5 days after a payout. Your remaining balance after payout would be " . round(($balance - $hours) / 8, 2) . " day(s).",
            ]);
        }

        DB::transaction(function () use ($data, $hours, $hrPersonId) {
            $payout = \App\Modules\HR\Leave\Models\LeavePayoutRecord::create([
                'employee_id'    => $hrPersonId,
                'leave_type_id'  => $data['leave_type_id'],
                'leave_cycle_id' => \App\Modules\HR\Leave\Models\LeaveCycle::active()?->id,
                'hours_paid_out' => $hours,
                'payout_date'    => now()->toDateString(),
                'approved_by'    => null,
                'status'         => 'pending',
                'notes'          => $data['notes'] ?? null,
            ]);

            $this->balanceService->payout(
                hrPersonId:     $hrPersonId,
                leaveTypeId:    $data['leave_type_id'],
                hours:          $hours,
                payoutRecordId: $payout->id,
                reference:      "Employee request #{$payout->id}",
            );

            // Notify HR
            $hrUsers = \App\Core\User\Models\User::role(['HR', 'administrator', 'super admin'])->get();
            $employee = \App\Modules\HR\Hr\Models\HRPerson::find($hrPersonId);
            $empName  = $employee ? trim($employee->first_name . ' ' . $employee->surname) : "Employee #{$hrPersonId}";
            $lt       = LeaveType::find($data['leave_type_id']);
            foreach ($hrUsers as $hrUser) {
                $this->notifySilently($hrUser, new \App\Modules\HR\Leave\Notifications\LeavePayoutRequestedNotification($payout, $empName, $lt?->name ?? ''));
            }
        });

        return back()->with('success', "Payout request for {$data['days']} day(s) submitted. HR has been notified.");
    }

    // ── Leave Payouts (HR admin) ──────────────────────────────────────────────

    public function leavePayouts(): Response
    {
        $payouts = \App\Modules\HR\Leave\Models\LeavePayoutRecord::with(['employee', 'leaveType'])
            ->orderByDesc('payout_date')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn($p) => [
                'id'              => $p->id,
                'employee_name'   => trim(($p->employee?->first_name ?? '') . ' ' . ($p->employee?->surname ?? '')) ?: "Employee #{$p->employee_id}",
                'employee_id'     => $p->employee_id,
                'leave_type'      => $p->leaveType?->name ?? '—',
                'leave_type_id'   => $p->leave_type_id,
                'hours_paid_out'  => (float) $p->hours_paid_out,
                'days_paid_out'   => round((float) $p->hours_paid_out / 8, 2),
                'amount'          => (float) ($p->amount ?? 0),
                'payout_date'     => $p->payout_date?->format('Y-m-d'),
                'payroll_reference' => $p->payroll_reference ?? '',
                'status'          => $p->status,
                'notes'           => $p->notes ?? '',
            ]);

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->orderByRaw("TRIM(CONCAT(first_name, ' ', surname))")
            ->get(['id', DB::raw("TRIM(CONCAT(first_name, ' ', surname)) as name")])
            ->map(fn($e) => ['id' => $e->id, 'name' => $e->name]);

        $leaveTypes = LeaveType::where('status', 1)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('HR/Leave/Payouts', [
            'payouts'    => $payouts,
            'employees'  => $employees,
            'leaveTypes' => $leaveTypes,
        ]);
    }

    public function storeLeavePayouts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'       => 'required|integer|exists:hr_people,id',
            'leave_type_id'     => 'required|integer|exists:leave_types,id',
            'days'              => 'required|numeric|min:0.5|max:365',
            'amount'            => 'nullable|numeric|min:0',
            'payout_date'       => 'required|date_format:Y-m-d',
            'payroll_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $hours = round($data['days'] * 8, 2);

        DB::transaction(function () use ($data, $hours) {
            $payout = \App\Modules\HR\Leave\Models\LeavePayoutRecord::create([
                'employee_id'       => $data['employee_id'],
                'leave_type_id'     => $data['leave_type_id'],
                'leave_cycle_id'    => \App\Modules\HR\Leave\Models\LeaveCycle::active()?->id,
                'hours_paid_out'    => $hours,
                'amount'            => $data['amount'] ?? null,
                'payout_date'       => $data['payout_date'],
                'payroll_reference' => $data['payroll_reference'] ?? null,
                'approved_by'       => Auth::id(),
                'status'            => 'pending',
                'notes'             => $data['notes'] ?? null,
            ]);

            $this->balanceService->payout(
                hrPersonId:     $data['employee_id'],
                leaveTypeId:    $data['leave_type_id'],
                hours:          $hours,
                payoutRecordId: $payout->id,
                reference:      $data['payroll_reference'] ?? '',
            );
        });

        return back()->with('success', "Leave payout of {$data['days']} day(s) recorded.");
    }

    public function updatePayoutStatus(Request $request, \App\Modules\HR\Leave\Models\LeavePayoutRecord $payoutRecord): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,processed,reversed',
        ]);

        $payoutRecord->update(['status' => $data['status']]);

        return back()->with('success', "Payout status updated to {$data['status']}.");
    }

    public function reports(): Response
    {
        $leaveTypes  = LeaveType::where('status', 1)->get(['id', 'name']);
        $departments = CompanyUnit::where('active', true)
            ->where('type', 'department')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->orderByRaw("TRIM(CONCAT(first_name, ' ', surname))")
            ->get(['id', DB::raw("TRIM(CONCAT(first_name, ' ', surname)) as name")])
            ->map(fn($e) => ['id' => $e->id, 'name' => $e->name])
            ->values();

        // Build employee_id → department_name map with pivot-table fallback.
        // Employees assigned via company_unit_user_pivots (and no division_level_1) are resolved here.
        $deptByEmployee = DB::table('hr_people as hp')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(hp.division_level_1, cuup.company_id)'))
            ->select('hp.id', 'cu.name as dept_name')
            ->pluck('dept_name', 'id')
            ->toArray();

        $takenRequests = LeaveRequest::with(['employee', 'leaveType'])
            ->where('leave_requests.status', 'approved')
            ->whereYear('leave_requests.start_date', now()->year)
            ->latest('leave_requests.start_date')
            ->limit(200)
            ->get();

        // Resolve the final approver per request from the approval levels log.
        $approvedByMap = DB::table('leave_approval_levels as lal')
            ->leftJoin('users as u', 'u.id', '=', 'lal.approver_id')
            ->leftJoin('hr_people as ahp', 'ahp.user_id', '=', 'u.id')
            ->whereIn('lal.leave_request_id', $takenRequests->pluck('id')->toArray() ?: [0])
            ->where('lal.status', 'approved')
            ->orderByDesc('lal.level')
            ->select(
                'lal.leave_request_id',
                DB::raw("COALESCE(TRIM(CONCAT(ahp.first_name, ' ', ahp.surname)), u.email) as approver_name")
            )
            ->get()
            ->groupBy('leave_request_id')
            ->map(fn($g) => $g->first()->approver_name)
            ->toArray();

        $taken = $takenRequests->map(fn($r) => [
            'employee_name' => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')) ?: "Employee #{$r->employee_id}",
            'department'    => $deptByEmployee[$r->employee_id] ?? '—',
            'leave_type'    => $r->leaveType?->name ?? 'Unknown',
            'start_date'    => $r->start_date?->format('Y-m-d'),
            'end_date'      => $r->end_date?->format('Y-m-d'),
            'working_days'  => $r->working_days,
            'status'        => $r->status?->value ?? (string) $r->status,
            'approved_by'   => $approvedByMap[$r->id] ?? '—',
        ]);

        $pending = LeaveRequest::with(['employee', 'leaveType'])
            ->pending()
            ->latest('leave_requests.created_at')
            ->limit(200)
            ->get()
            ->map(fn($r) => [
                'employee_name' => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')) ?: "Employee #{$r->employee_id}",
                'department'    => $deptByEmployee[$r->employee_id] ?? '—',
                'leave_type'    => $r->leaveType?->name ?? 'Unknown',
                'start_date'    => $r->start_date?->format('Y-m-d'),
                'end_date'      => $r->end_date?->format('Y-m-d'),
                'working_days'  => $r->working_days,
                'created_at'    => $r->created_at?->format('Y-m-d'),
                'days_waiting'  => $r->created_at ? $r->created_at->startOfDay()->diffInDays(now()->startOfDay()) : 0,
            ]);

        $cancelledRows = DB::table('leave_requests as lr')
            ->join('hr_people as emp', 'emp.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'lr.cancelled_by_user_id')
            ->leftJoin('hr_people as cbp', 'cbp.user_id', '=', 'cu.id')
            ->where('lr.status', 'cancelled')
            ->latest('lr.updated_at')
            ->limit(200)
            ->select(
                'lr.employee_id',
                'lr.working_days',
                'lr.start_date',
                'lr.end_date',
                'lr.cancellation_reason',
                DB::raw("TRIM(CONCAT(emp.first_name, ' ', emp.surname)) as employee_name"),
                'lt.name as leave_type',
                DB::raw("COALESCE(TRIM(CONCAT(cbp.first_name, ' ', cbp.surname)), cu.email, '') as cancelled_by"),
            )
            ->get();

        $cancelled = $cancelledRows->map(fn($r) => [
            'employee_name' => $r->employee_name ?: "Employee #{$r->employee_id}",
            'department'    => $deptByEmployee[$r->employee_id] ?? '—',
            'leave_type'    => $r->leave_type,
            'start_date'    => $r->start_date,
            'end_date'      => $r->end_date,
            'working_days'  => $r->working_days,
            'cancelled_by'  => $r->cancelled_by,
            'cancel_reason' => $r->cancellation_reason ?? '',
        ]);

        $anomalies = LeaveAnomalyFlag::with('employee')
            ->latest('detected_at')
            ->limit(200)
            ->get()
            ->map(fn($f) => [
                'employee_name' => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'department'    => $deptByEmployee[$f->employee_id] ?? '—',
                'anomaly_type'  => method_exists($f->anomaly_type ?? '', 'label') ? $f->anomaly_type->label() : (string) ($f->anomaly_type ?? ''),
                'severity'      => $f->severity ?? '',
                'detected_at'   => $f->detected_at?->format('Y-m-d'),
                'ai_score'      => $f->ai_confidence_score ?? null,
                'status'        => $f->false_positive ? 'resolved' : 'open',
                'action_taken'  => $f->reviewed_by ? 'Reviewed' : null,
            ]);

        $deptTrendsRaw = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(hp.division_level_1, cuup.company_id)'))
            ->where('lr.status', 'approved')
            ->whereYear('lr.start_date', now()->year)
            ->selectRaw("COALESCE(cu.name, '—') as department, lt.name as leave_type, count(*) as total_requests, SUM(lr.working_days) as total_days")
            ->groupByRaw("COALESCE(cu.name, '—'), lt.name")
            ->get();

        // Monthly breakdown to determine peak month per dept+leave_type
        $monthlyBreakdown = DB::table('leave_requests as lr')
            ->join('hr_people as hp2', 'hp2.id', '=', 'lr.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup2'),
                'cuup2.user_id', '=', 'hp2.user_id'
            )
            ->leftJoin('company_units as cu2', 'cu2.id', '=', DB::raw('COALESCE(hp2.division_level_1, cuup2.company_id)'))
            ->join('leave_types as lt2', 'lt2.id', '=', 'lr.leave_type_id')
            ->where('lr.status', 'approved')
            ->whereYear('lr.start_date', now()->year)
            ->selectRaw("COALESCE(cu2.name, '—') as department, lt2.name as leave_type, TO_CHAR(lr.start_date, 'Mon') as month_name, count(*) as cnt")
            ->groupByRaw("COALESCE(cu2.name, '—'), lt2.name, TO_CHAR(lr.start_date, 'Mon')")
            ->get();

        $peakMonths = [];
        foreach ($monthlyBreakdown as $mb) {
            $k = $mb->department . '|' . $mb->leave_type;
            if (!isset($peakMonths[$k]) || $mb->cnt > $peakMonths[$k]['cnt']) {
                $peakMonths[$k] = ['month' => $mb->month_name, 'cnt' => $mb->cnt];
            }
        }

        $deptTrends = $deptTrendsRaw->map(fn($r) => [
            'department'            => $r->department,
            'leave_type'            => $r->leave_type,
            'total_requests'        => (int) $r->total_requests,
            'total_days'            => (int) $r->total_days,
            'avg_days_per_employee' => round($r->total_days / max($r->total_requests, 1), 1),
            'peak_month'            => $peakMonths[$r->department . '|' . $r->leave_type]['month'] ?? '—',
        ]);

        $cycle   = LeaveCycle::active();
        $cycleId = $cycle?->id;

        // ── Balance report — fully populated per-employee rows ────────────────
        // Get latest transaction id per employee+leave_type
        $latestTxIds = DB::table('leave_balance_transactions')
            ->when($cycleId, fn($q) => $q->where('leave_cycle_id', $cycleId))
            ->selectRaw('employee_id, leave_type_id, MAX(id) as latest_id')
            ->groupBy('employee_id', 'leave_type_id')
            ->get()
            ->keyBy(fn($r) => "{$r->employee_id}_{$r->leave_type_id}");

        // Fetch the balance_after for those rows (qualify 'id' to avoid ambiguity with the joined hp.id)
        $latestBalances = DB::table('leave_balance_transactions')
            ->whereIn('leave_balance_transactions.id', $latestTxIds->pluck('latest_id'))
            ->join('hr_people as hp', 'hp.id', '=', 'leave_balance_transactions.employee_id')
            ->where('hp.status', 1)
            ->get([
                'leave_balance_transactions.id',
                'leave_balance_transactions.employee_id',
                'leave_balance_transactions.leave_type_id',
                'leave_balance_transactions.balance_after',
            ])
            ->keyBy(fn($r) => "{$r->employee_id}_{$r->leave_type_id}");

        // Entitlement = sum of opening + allocation credits per employee+leave_type (current cycle)
        $entitlements = DB::table('leave_balance_transactions')
            ->when($cycleId, fn($q) => $q->where('leave_cycle_id', $cycleId))
            ->whereIn('transaction_type', ['allocation', 'opening_balance'])
            ->where('hours', '>', 0)
            ->selectRaw('employee_id, leave_type_id, SUM(hours) as total')
            ->groupBy('employee_id', 'leave_type_id')
            ->get()
            ->keyBy(fn($r) => "{$r->employee_id}_{$r->leave_type_id}");

        // Carryover per employee+leave_type
        $carryovers = DB::table('leave_balance_transactions')
            ->when($cycleId, fn($q) => $q->where('leave_cycle_id', $cycleId))
            ->where('transaction_type', 'carryover')
            ->where('hours', '>', 0)
            ->selectRaw('employee_id, leave_type_id, SUM(hours) as total')
            ->groupBy('employee_id', 'leave_type_id')
            ->get()
            ->keyBy(fn($r) => "{$r->employee_id}_{$r->leave_type_id}");

        // Used = sum of consumed hours per employee+leave_type
        $consumed = DB::table('leave_balance_transactions')
            ->when($cycleId, fn($q) => $q->where('leave_cycle_id', $cycleId))
            ->where('transaction_type', 'request_consumption')
            ->selectRaw('employee_id, leave_type_id, SUM(ABS(hours)) as total')
            ->groupBy('employee_id', 'leave_type_id')
            ->get()
            ->keyBy(fn($r) => "{$r->employee_id}_{$r->leave_type_id}");

        // Employee lookup: id → {name, department} with pivot-table fallback
        $empDetails = DB::table('hr_people as hp')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(hp.division_level_1, cuup.company_id)'))
            ->where('hp.status', 1)
            ->get([
                'hp.id',
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as full_name"),
                'cu.name as department',
            ])
            ->keyBy('id');

        $leaveTypeNames = DB::table('leave_types')->where('status', 1)->pluck('name', 'id')->toArray();

        $balances = collect();
        foreach ($latestBalances as $key => $tx) {
            $emp = $empDetails[$tx->employee_id] ?? null;
            if (! $emp) {
                continue;
            }
            $ltName = $leaveTypeNames[$tx->leave_type_id] ?? null;
            if (! $ltName) {
                continue;
            }

            $balanceHours   = (float) $tx->balance_after;
            $entitlement    = (float) (($entitlements[$key]->total ?? 0) + ($carryovers[$key]->total ?? 0));
            $usedHours      = (float) ($consumed[$key]->total ?? 0);
            $carryoverHours = (float) ($carryovers[$key]->total ?? 0);

            $balances->push([
                'employee_name'    => $emp->full_name,
                'department'       => $emp->department ?? '—',
                'leave_type'       => $ltName,
                'entitlement_days' => round($entitlement / 8, 1),
                'used_days'        => round($usedHours / 8, 1),
                'remaining_days'   => round($balanceHours / 8, 1),
                'carry_over_days'  => round($carryoverHours / 8, 1),
                'cycle'            => $cycle?->name ?? '—',
                'balance_hours'    => $balanceHours,
                'balance_days'     => round($balanceHours / 8, 1),
                'is_negative'      => $balanceHours < 0,
            ]);
        }

        $negativeBalances = $balances->filter(fn($b) => $b['is_negative'])->values();

        // Allowances (allocation transactions in current cycle)
        $allowances = DB::table('leave_balance_transactions as lbt')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup_a'),
                'cuup_a.user_id', '=', 'hp.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(hp.division_level_1, cuup_a.company_id)'))
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->whereIn('lbt.transaction_type', ['allocation', 'opening_balance', 'carryover'])
            ->when($cycle, fn($q) => $q->where('lbt.leave_cycle_id', $cycle->id))
            ->orderByDesc('lbt.created_at')
            ->limit(500)
            ->select(
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as employee_name"),
                'cu.name as department',
                'lt.name as leave_type',
                'lbt.transaction_type as type',
                'lbt.hours',
                'lbt.description',
                'lbt.created_at as allocated_at'
            )
            ->get()
            ->map(fn($r) => [
                'employee_name' => $r->employee_name,
                'department'    => $r->department ?? '—',
                'leave_type'    => $r->leave_type,
                'type'          => ucfirst(str_replace('_', ' ', $r->type)),
                'hours'         => (float) $r->hours,
                'description'   => $r->description,
                'allocated_at'  => $r->allocated_at ? \Carbon\Carbon::parse($r->allocated_at)->format('Y-m-d') : null,
            ]);

        // Leave liability report
        $liability = $balances
            ->filter(fn($b) => (float) $b['balance_hours'] > 0)
            ->map(fn($b) => array_merge($b, [
                'estimated_liability_hours' => (float) $b['balance_hours'],
            ]))
            ->values();

        // Payouts
        $payouts = \App\Modules\HR\Leave\Models\LeavePayoutRecord::with(['employee', 'leaveType'])
            ->latest('payout_date')
            ->limit(200)
            ->get()
            ->map(fn($p) => [
                'employee_name' => trim(($p->employee?->first_name ?? '') . ' ' . ($p->employee?->surname ?? '')) ?: "Employee #{$p->employee_id}",
                'department'    => $deptByEmployee[$p->employee_id] ?? '—',
                'leave_type'    => $p->leaveType?->name ?? 'Unknown',
                'hours_paid_out'=> (float) $p->hours_paid_out,
                'amount'        => (float) $p->amount,
                'payout_date'   => $p->payout_date?->format('Y-m-d'),
                'status'        => $p->status,
                'payroll_ref'   => $p->payroll_reference,
            ]);

        // Transaction ledger — grouped by employee (alphabetical), oldest-to-newest within each
        $transactions = DB::table('leave_balance_transactions as lbt')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup_t'),
                'cuup_t.user_id', '=', 'hp.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(hp.division_level_1, cuup_t.company_id)'))
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->orderByRaw("TRIM(CONCAT(hp.first_name, ' ', hp.surname))")
            ->orderBy('lbt.created_at')
            ->limit(500)
            ->select(
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as employee_name"),
                'cu.name as department',
                'lt.name as leave_type',
                'lbt.transaction_type',
                'lbt.hours',
                'lbt.balance_after',
                'lbt.description as reference',
                'lbt.created_at',
            )
            ->get()
            ->map(fn($r) => [
                'employee_name'       => $r->employee_name,
                'department'          => $r->department ?? '—',
                'leave_type'          => $r->leave_type,
                'transaction_type'    => $r->transaction_type,
                'hours'               => round((float) $r->hours, 2),
                'balance_after_hours' => round((float) $r->balance_after, 2),
                'days'                => round((float) $r->hours / 8, 2),
                'balance_after_days'  => round((float) $r->balance_after / 8, 2),
                'reference'           => $r->reference,
                'created_at'          => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d') : null,
            ]);

        // Policy violation report
        $violations = LeaveAnomalyFlag::with(['employee', 'leaveRequest.leaveType'])
            ->where('anomaly_type', LeaveAnomalyType::PolicyViolation->value)
            ->latest('detected_at')
            ->limit(200)
            ->get()
            ->map(fn($f) => [
                'employee_name'  => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'department'     => $deptByEmployee[$f->employee_id] ?? '—',
                'leave_type'     => $f->leaveRequest?->leaveType?->name ?? '—',
                'violation_type' => $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : (string) $f->anomaly_type,
                'severity'       => $f->severity ?? 'medium',
                'created_at'     => $f->detected_at?->format('Y-m-d'),
                'status'         => $f->false_positive ? 'resolved' : 'open',
            ]);

        // Burnout risk report — merge AI flags (if any) with computed risk from leave patterns
        $burnoutFlagRows = LeaveAnomalyFlag::with('employee')
            ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
            ->latest('detected_at')
            ->limit(200)
            ->get()
            ->map(fn($f) => [
                'employee_name'    => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'department'       => $deptByEmployee[$f->employee_id] ?? '—',
                'risk_score'       => $f->ai_confidence_score ?? null,
                'leave_frequency'  => data_get($f->metadata, 'leave_frequency', '—'),
                'days_used'        => data_get($f->metadata, 'days_used', '—'),
                'sick_days'        => data_get($f->metadata, 'sick_days', '—'),
                'flagged_at'       => $f->detected_at?->format('Y-m-d'),
                'reviewed'         => (bool) $f->reviewed_by,
            ]);

        // Computed burnout risk: employees with ≥4 leave requests this year, scored by frequency + days used
        $computedBurnout = DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->where('lr.status', 'approved')
            ->whereYear('lr.start_date', now()->year)
            ->groupBy('lr.employee_id', 'hp.first_name', 'hp.surname', 'hp.id')
            ->havingRaw('count(*) >= 3')
            ->selectRaw("lr.employee_id, hp.first_name, hp.surname, hp.id as hr_id, count(*) as freq, SUM(lr.working_days) as days_used, SUM(CASE WHEN lr.leave_type_id IN (SELECT id FROM leave_types WHERE LOWER(name) LIKE '%sick%') THEN lr.working_days ELSE 0 END) as sick_days")
            ->orderByRaw('count(*) DESC')
            ->limit(100)
            ->get()
            ->map(function ($r) use ($deptByEmployee) {
                $freq     = (int) $r->freq;
                $daysUsed = (float) $r->days_used;
                $sickDays = (float) $r->sick_days;
                // Simple risk score: (frequency * 0.4) + (days_used * 0.04) + (sick_days * 0.15), capped at 1
                $score = min(round(($freq * 0.4 + $daysUsed * 0.04 + $sickDays * 0.15), 2), 1.0);
                return [
                    'employee_name'   => trim($r->first_name . ' ' . $r->surname) ?: "Employee #{$r->employee_id}",
                    'department'      => $deptByEmployee[$r->employee_id] ?? '—',
                    'risk_score'      => $score,
                    'leave_frequency' => $freq,
                    'days_used'       => round($daysUsed, 1),
                    'sick_days'       => round($sickDays, 1),
                    'flagged_at'      => null,
                    'reviewed'        => false,
                ];
            });

        // Merge: AI flags first, then computed rows not already in flags
        $flaggedIds   = $burnoutFlagRows->pluck('employee_name')->flip()->toArray();
        $burnoutRisks = $burnoutFlagRows->merge(
            $computedBurnout->filter(fn($r) => !isset($flaggedIds[$r['employee_name']]))
        )->values();

        $leaveConfig = LeaveConfiguration::first();

        return Inertia::render('HR/Leave/Reports', [
            'balances'          => $balances->values(),
            'negativeBalances'  => $negativeBalances,
            'taken'             => $taken,
            'transactions'      => $transactions->values(),
            'allowances'        => $allowances->values(),
            'payouts'           => $payouts,
            'liability'         => $liability,
            'pending'           => $pending,
            'cancelled'         => $cancelled,
            'violations'        => $violations->values(),
            'anomalies'         => $anomalies,
            'burnoutRisks'      => $burnoutRisks->values(),
            'deptTrends'        => $deptTrends,
            'leaveTypes'        => $leaveTypes,
            'departments'       => $departments,
            'employees'         => $employees,
            'balanceAsOfEnabled'=> (bool) ($leaveConfig?->balance_as_of_enabled ?? false),
        ]);
    }

    public function balanceAsOfReport(Request $request): JsonResponse
    {
        $request->validate([
            'date'         => 'required|date_format:Y-m-d',
            'employee_ids' => 'nullable|string',
            'dept_ids'     => 'nullable|string',
        ]);

        $asOfDate    = $request->input('date');
        $employeeIds = array_filter(explode(',', $request->input('employee_ids', '')), 'is_numeric');
        $deptIds     = array_filter(explode(',', $request->input('dept_ids', '')), 'is_numeric');

        // Latest transaction per employee+leave_type as of the chosen date
        $sub = DB::table('leave_balance_transactions')
            ->whereRaw('DATE(created_at) <= ?', [$asOfDate])
            ->selectRaw('employee_id, leave_type_id, MAX(id) as latest_id')
            ->groupBy('employee_id', 'leave_type_id');

        $rows = DB::table('leave_balance_transactions as lbt')
            ->joinSub($sub, 'latest', fn($j) => $j->on('lbt.id', '=', 'latest.latest_id'))
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'hp.division_level_1')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->where('hp.status', 1)
            ->when(!empty($employeeIds), fn($q) => $q->whereIn('hp.id', $employeeIds))
            ->when(!empty($deptIds), function ($q) use ($deptIds) {
                // Resolve employees via pivot table (company_unit_user_pivots.user_id → hr_people.user_id)
                // then fall back to division_level_1 for employees without a pivot record.
                $pivotEmpIds = DB::table('company_unit_user_pivots as cuup')
                    ->join('hr_people as hp2', 'hp2.user_id', '=', 'cuup.user_id')
                    ->whereIn('cuup.company_id', $deptIds)
                    ->pluck('hp2.id');
                $q->where(function ($sub) use ($deptIds, $pivotEmpIds) {
                    $sub->whereIn('hp.division_level_1', $deptIds)
                        ->orWhereIn('hp.id', $pivotEmpIds);
                });
            })
            ->select(
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as employee_name"),
                'cu.name as department',
                'lt.name as leave_type',
                'lbt.balance_after',
            )
            ->orderByRaw("TRIM(CONCAT(hp.first_name, ' ', hp.surname))")
            ->get()
            ->map(fn($r) => [
                'employee_name' => $r->employee_name,
                'department'    => $r->department ?? '—',
                'leave_type'    => $r->leave_type,
                'balance_hours' => round((float) $r->balance_after, 2),
                'balance_days'  => round((float) $r->balance_after / 8, 1),
                'as_of_date'    => $asOfDate,
            ]);

        return response()->json([
            'rows'    => $rows->values(),
            'as_of'   => $asOfDate,
            'count'   => $rows->count(),
        ]);
    }

    public function generateReport(string $key): JsonResponse
    {
        $count = match ($key) {
            'taken'       => LeaveRequest::where('status', 'approved')->count(),
            'pending'     => LeaveRequest::pending()->count(),
            'cancelled'   => LeaveRequest::where('status', 'cancelled')->count(),
            'anomaly'     => LeaveAnomalyFlag::count(),
            'burnout'     => LeaveAnomalyFlag::where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)->count(),
            'violation'   => LeaveAnomalyFlag::where('anomaly_type', LeaveAnomalyType::PolicyViolation->value)->count(),
            'transaction' => DB::table('leave_balance_transactions')->count(),
            'allowance'   => DB::table('leave_balance_transactions')->whereIn('transaction_type', ['allocation', 'opening_balance', 'carryover'])->count(),
            'payout'      => DB::table('leave_payout_records')->count(),
            'balance'     => DB::table('leave_balance_transactions')->distinct()->count('employee_id'),
            'negative'    => DB::table('leave_balance_transactions')->where('balance_after', '<', 0)->distinct()->count('employee_id'),
            'liability'   => DB::table('leave_balance_transactions')->where('balance_after', '>', 0)->distinct()->count('employee_id'),
            'trend'       => LeaveRequest::where('status', 'approved')->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')->groupBy('leave_types.id')->count(),
            default       => 0,
        };

        return response()->json(['key' => $key, 'count' => $count]);
    }

    public function queueReport(Request $request, string $key): JsonResponse
    {
        $request->validate(['email' => 'nullable|email']);

        GenerateLeaveReportJob::dispatch(
            $key,
            $request->input('email', Auth::user()?->email),
        );

        return response()->json(['queued' => true, 'key' => $key]);
    }

    public function exportReport(string $key, string $format): mixed
    {
        [$headers, $rows] = $this->buildReportData($key);
        $filename = "leave-{$key}-" . now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(
                new \App\Modules\HR\Leave\Exports\LeaveReportExport($headers, $rows),
                "{$filename}.xlsx",
            );
        }

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.leave-report', [
                'title'   => ucfirst($key) . ' Leave Report',
                'headers' => $headers,
                'rows'    => $rows,
                'date'    => now()->format('d M Y'),
            ])->setPaper('a4', 'landscape');

            return $pdf->download("{$filename}.pdf");
        }

        // Fallback: CSV stream
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Public so the scheduled-reports dispatcher can reuse the same definitions. */
    public function buildReportData(string $key): array
    {
        return match ($key) {
            'taken' => [
                ['Employee', 'Leave Type', 'Start Date', 'End Date', 'Working Days', 'Status'],
                LeaveRequest::with(['employee', 'leaveType'])
                    ->where('leave_requests.status', 'approved')
                    ->latest('leave_requests.start_date')
                    ->get()
                    ->map(fn($r) => [
                        trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                        $r->leaveType?->name ?? '',
                        $r->start_date?->format('d M Y'),
                        $r->end_date?->format('d M Y'),
                        $r->working_days,
                        $r->status?->value ?? $r->status,
                    ])->toArray(),
            ],
            'pending' => [
                ['Employee', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Days Waiting'],
                LeaveRequest::with(['employee', 'leaveType'])
                    ->pending()
                    ->get()
                    ->map(fn($r) => [
                        trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                        $r->leaveType?->name ?? '',
                        $r->start_date?->format('d M Y'),
                        $r->end_date?->format('d M Y'),
                        $r->working_days,
                        (int) now()->diffInDays($r->created_at),
                    ])->toArray(),
            ],
            'cancelled' => [
                ['Employee', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Reason'],
                LeaveRequest::with(['employee', 'leaveType'])
                    ->where('leave_requests.status', 'cancelled')
                    ->latest('leave_requests.updated_at')
                    ->get()
                    ->map(fn($r) => [
                        trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                        $r->leaveType?->name ?? '',
                        $r->start_date?->format('d M Y'),
                        $r->end_date?->format('d M Y'),
                        $r->working_days,
                        $r->cancellation_reason ?? '',
                    ])->toArray(),
            ],
            'transaction' => [
                ['Employee', 'Leave Type', 'Type', 'Amount (days)', 'Balance (days)', 'Reference', 'Date'],
                DB::table('leave_balance_transactions as lbt')
                    ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
                    ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
                    ->orderByDesc('lbt.created_at')
                    ->select(DB::raw("TRIM(CONCAT(hp.first_name,' ',hp.surname)) as name"), 'lt.name as lt_name', 'lbt.transaction_type', 'lbt.hours', 'lbt.balance_after', 'lbt.description', 'lbt.created_at')
                    ->get()
                    ->map(fn($r) => [$r->name, $r->lt_name, $r->transaction_type, round((float)$r->hours / 8, 2), round((float)$r->balance_after / 8, 2), $r->description, \Carbon\Carbon::parse($r->created_at)->format('d M Y')])
                    ->toArray(),
            ],
            'anomaly' => [
                ['Employee', 'Anomaly Type', 'Severity', 'Detected At', 'Status'],
                LeaveAnomalyFlag::with('employee')->get()->map(fn($f) => [
                    trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                    $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : (string) $f->anomaly_type,
                    $f->severity ?? '',
                    $f->detected_at?->format('d M Y'),
                    $f->false_positive ? 'Resolved' : 'Open',
                ])->toArray(),
            ],
            'burnout' => [
                ['Employee', 'Risk Score', 'Leave Frequency', 'Days Used', 'Sick Days', 'Flagged At', 'Reviewed'],
                LeaveAnomalyFlag::with('employee')->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)->get()->map(fn($f) => [
                    trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                    $f->ai_confidence_score ?? '',
                    data_get($f->metadata, 'leave_frequency', ''),
                    data_get($f->metadata, 'days_used', ''),
                    data_get($f->metadata, 'sick_days', ''),
                    $f->detected_at?->format('d M Y'),
                    $f->reviewed_by ? 'Yes' : 'No',
                ])->toArray(),
            ],
            'violation' => [
                ['Employee', 'Leave Type', 'Violation Type', 'Severity', 'Date', 'Status'],
                LeaveAnomalyFlag::with(['employee', 'leaveRequest.leaveType'])->where('anomaly_type', LeaveAnomalyType::PolicyViolation->value)->get()->map(fn($f) => [
                    trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                    $f->leaveRequest?->leaveType?->name ?? '',
                    $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : '',
                    $f->severity ?? '',
                    $f->detected_at?->format('d M Y'),
                    $f->false_positive ? 'Resolved' : 'Open',
                ])->toArray(),
            ],
            default => [['Report', 'Data'], []],
        };
    }

    // ── Policy Governance ────────────────────────────────────────────────────

    public function policyViolations(): Response
    {
        $violations = PolicyViolation::with(['policy', 'rule', 'user'])
            ->whereJsonContains('event_snapshot->module', 'Leave')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn($v) => [
                'id'          => $v->id,
                'policy_name' => $v->policy?->name ?? 'Unknown',
                'rule_name'   => $v->rule?->name ?? 'Unknown',
                'severity'    => $v->severity,
                'outcome'     => $v->outcome,
                'status'      => $v->status,
                'employee'    => $v->user?->name ?? '—',
                'leave_type'  => data_get($v->event_snapshot, 'leave_type_name', '—'),
                'action'      => data_get($v->event_snapshot, 'action', '—'),
                'detected_at' => $v->created_at?->format('d M Y H:i'),
                'resolved_at' => $v->resolved_at?->format('d M Y'),
            ]);

        $conflicts = $this->policySyncService->openConflicts()
            ->map(fn($c) => [
                'id'          => $c->id,
                'policy_a'    => $c->policyA?->name,
                'policy_b'    => $c->policyB?->name,
                'type'        => $c->conflict_type,
                'description' => $c->description,
                'status'      => $c->status,
                'detected_at' => $c->created_at?->format('d M Y'),
            ]);

        $aiSummary = null;
        if ($conflicts->isNotEmpty()) {
            try {
                $aiSummary = $this->aiAdvisorService->summarizeConflicts(
                    $this->currentHrPersonId() ?? 0,
                    $conflicts->toArray(),
                );
            } catch (\Throwable) {}
        }

        $auditLog = PolicyRuleAuditLog::with(['leaveRequest', 'policyRule'])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn($l) => [
                'id'                => $l->id,
                'rule_code'         => $l->rule_code,
                'event_type'        => $l->event_type,
                'result'            => $l->evaluation_result,
                'detail'            => $l->evaluation_detail,
                'request_number'    => $l->leaveRequest?->request_number,
                'override_reason'   => $l->override_reason,
                'ai_explanation'    => $l->ai_explanation,
                'created_at'        => $l->created_at?->format('d M Y H:i'),
            ]);

        $summary = [
            'total_violations' => $violations->count(),
            'open'             => $violations->where('status', 'open')->count(),
            'blocked'          => $violations->where('outcome', 'block')->count(),
            'open_conflicts'   => $conflicts->count(),
        ];

        return Inertia::render('HR/Leave/PolicyViolations', [
            'violations'  => $violations,
            'conflicts'   => $conflicts,
            'auditLog'    => $auditLog,
            'aiSummary'   => $aiSummary,
            'summary'     => $summary,
        ]);
    }

    public function executiveDashboard(): Response
    {
        $today = now()->format('Y-m-d');
        $year  = now()->year;

        // ── Company-wide KPIs ────────────────────────────────────────────────
        $headcount    = DB::table('hr_people')->where('status', 1)->count();
        $onLeaveToday = DB::table('leave_requests')
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();
        $pending      = DB::table('leave_requests')
            ->whereIn('status', ['pending_manager', 'pending_hr'])->count();
        $coveragePct  = $headcount > 0 ? max(0, round(($headcount - $onLeaveToday) / $headcount * 100)) : 100;
        $burnoutCount = LeaveAnomalyFlag::open()
            ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)->count();
        $anomalyCount = LeaveAnomalyFlag::open()->count();
        $violationCount = PolicyViolation::where('status', 'open')->count();

        // ── Leave liability (current cycle) ──────────────────────────────────
        $liabilityByType = DB::table('leave_balance_transactions as lbt')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->whereIn('lbt.id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('leave_balance_transactions')
                    ->groupBy('employee_id', 'leave_type_id');
            })
            ->where('lbt.balance_after', '>', 0)
            ->where('hp.status', 1)
            ->selectRaw('lt.name as leave_type, count(distinct lbt.employee_id) as employee_count, SUM(lbt.balance_after) as total_hours')
            ->groupBy('lt.name')
            ->orderByDesc('total_hours')
            ->get()
            ->map(fn($r) => [
                'leave_type'     => $r->leave_type,
                'employee_count' => (int) $r->employee_count,
                'total_hours'    => round((float) $r->total_hours, 1),
                'total_days'     => round((float) $r->total_hours / 8, 1),
            ])
            ->values();

        $totalLiabilityDays = $liabilityByType->sum('total_days');

        // ── Department pressure (all divisions) ──────────────────────────────
        $divisions = DB::table('company_units')->where('active', true)->orderBy('name')->get(['id', 'name']);
        $divisionPressure = $divisions->map(function ($div) use ($today) {
            $headcount = DB::table('hr_people')
                ->where('division_level_1', $div->id)->where('status', 1)->count();
            if ($headcount === 0) return null;

            $absent  = DB::table('leave_requests')
                ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
                ->where('hr_people.division_level_1', $div->id)
                ->where('leave_requests.status', 'approved')
                ->where('leave_requests.start_date', '<=', $today)
                ->where('leave_requests.end_date', '>=', $today)
                ->count();
            $pending  = DB::table('leave_requests')
                ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
                ->where('hr_people.division_level_1', $div->id)
                ->whereIn('leave_requests.status', ['pending_manager', 'pending_hr'])
                ->count();
            $burnout  = LeaveAnomalyFlag::open()
                ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
                ->whereIn('employee_id', DB::table('hr_people')
                    ->where('division_level_1', $div->id)->pluck('id'))
                ->count();
            $coverage = $headcount > 0 ? round(($headcount - $absent) / $headcount * 100) : 100;

            return [
                'division'    => $div->name,
                'headcount'   => $headcount,
                'absent'      => $absent,
                'pending'     => $pending,
                'coverage_pct'=> $coverage,
                'burnout_flags'=> $burnout,
                'pressure'    => $coverage < 70 ? 'critical' : ($coverage < 85 ? 'high' : ($coverage < 95 ? 'medium' : 'low')),
            ];
        })->filter()->sortBy('coverage_pct')->values();

        // ── Fallback: if no named divisions, group by division_level_1 ────────
        if ($divisionPressure->isEmpty()) {
            $divisionPressure = DB::table('hr_people')
                ->whereNotNull('division_level_1')
                ->where('status', 1)
                ->selectRaw('division_level_1 as div_id, count(*) as headcount')
                ->groupBy('division_level_1')
                ->get()
                ->map(function ($row) use ($today) {
                    $absent  = DB::table('leave_requests')
                        ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
                        ->where('hr_people.division_level_1', $row->div_id)
                        ->where('leave_requests.status', 'approved')
                        ->where('leave_requests.start_date', '<=', $today)
                        ->where('leave_requests.end_date', '>=', $today)
                        ->count();
                    $pending = DB::table('leave_requests')
                        ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
                        ->where('hr_people.division_level_1', $row->div_id)
                        ->whereIn('leave_requests.status', ['pending_manager', 'pending_hr'])
                        ->count();
                    $burnout = LeaveAnomalyFlag::open()
                        ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
                        ->whereIn('employee_id', DB::table('hr_people')
                            ->where('division_level_1', $row->div_id)->pluck('id'))
                        ->count();
                    $coverage = $row->headcount > 0 ? round(($row->headcount - $absent) / $row->headcount * 100) : 100;
                    return [
                        'division'    => "Division {$row->div_id}",
                        'headcount'   => (int) $row->headcount,
                        'absent'      => $absent,
                        'pending'     => $pending,
                        'coverage_pct'=> $coverage,
                        'burnout_flags'=> $burnout,
                        'pressure'    => $coverage < 70 ? 'critical' : ($coverage < 85 ? 'high' : ($coverage < 95 ? 'medium' : 'low')),
                    ];
                })->sortBy('coverage_pct')->values();
        }

        // ── Leave trends — monthly approved requests by leave type (this year) ─
        $monthlyRaw = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('lr.status', 'approved')
            ->whereYear('lr.start_date', $year)
            ->selectRaw("TO_CHAR(lr.start_date, 'Mon') as month, EXTRACT(MONTH FROM lr.start_date) as month_num, lt.name as leave_type, count(*) as total")
            ->groupByRaw("TO_CHAR(lr.start_date, 'Mon'), EXTRACT(MONTH FROM lr.start_date), lt.name")
            ->orderByRaw('EXTRACT(MONTH FROM lr.start_date)')
            ->get();

        $leaveTypeNames = $monthlyRaw->pluck('leave_type')->unique()->values()->toArray();
        $months = collect(range(1, 12))->map(fn($m) => date('M', mktime(0,0,0,$m,1)))->toArray();

        $trendSeries = collect($leaveTypeNames)->map(function ($type) use ($monthlyRaw, $months) {
            $data = collect($months)->map(function ($mon) use ($type, $monthlyRaw) {
                return (int) ($monthlyRaw->where('leave_type', $type)->where('month', $mon)->first()?->total ?? 0);
            })->toArray();
            return ['name' => $type, 'data' => $data];
        })->values()->toArray();

        // ── Burnout risk — top 10 employees ──────────────────────────────────
        $burnoutRisks = LeaveAnomalyFlag::with('employee')
            ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
            ->open()
            ->orderByDesc('ai_confidence_score')
            ->limit(10)
            ->get()
            ->map(fn($f) => [
                'employee_name'   => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'risk_score'      => $f->ai_confidence_score ?? 0,
                'days_used'       => data_get($f->metadata, 'days_used', '—'),
                'sick_days'       => data_get($f->metadata, 'sick_days', '—'),
                'flagged_at'      => $f->detected_at?->format('d M Y'),
            ]);

        // ── AI executive summary ──────────────────────────────────────────────
        $aiSummary = null;
        try {
            $raw = $this->aiAdvisorService->interpretWorkforceData(
                hrPersonId: 0,
                insightType: 'executive leave overview',
                data: [
                    'headcount'           => $headcount,
                    'on_leave_today'      => $onLeaveToday,
                    'coverage_pct'        => $coveragePct,
                    'pending_approvals'   => $pending,
                    'open_anomalies'      => $anomalyCount,
                    'burnout_risks'       => $burnoutCount,
                    'policy_violations'   => $violationCount,
                    'total_liability_days'=> $totalLiabilityDays,
                    'worst_departments'   => $divisionPressure->take(3)->map(fn($d) => "{$d['division']} ({$d['coverage_pct']}% coverage)")->values(),
                ],
                question: 'Provide an executive-level summary (under 200 words) with key risks, financial exposure, and 3 recommended actions for leadership. Use plain prose — no markdown headers or bullet symbols.',
            );
            // Strip any residual markdown the model still emits
            $aiSummary = preg_replace('/#{1,6}\s*/u', '', $raw);         // ### headers
            $aiSummary = preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $aiSummary); // **bold** / *italic*
            $aiSummary = preg_replace('/^[-*]\s+/mu', '• ', $aiSummary); // - bullets → •
            $aiSummary = trim($aiSummary);
        } catch (\Throwable) {}

        return Inertia::render('HR/Leave/ExecutiveDashboard', [
            'kpis' => [
                'headcount'        => $headcount,
                'on_leave_today'   => $onLeaveToday,
                'coverage_pct'     => $coveragePct,
                'pending'          => $pending,
                'anomalies'        => $anomalyCount,
                'burnout_risks'    => $burnoutCount,
                'violations'       => $violationCount,
                'liability_days'   => round($totalLiabilityDays, 1),
            ],
            'liabilityByType'   => $liabilityByType,
            'divisionPressure'  => $divisionPressure,
            'trendSeries'       => $trendSeries,
            'trendMonths'       => $months,
            'burnoutRisks'      => $burnoutRisks,
            'aiSummary'         => $aiSummary,
            'year'              => $year,
        ]);
    }

    public function hrDashboard(): Response
    {
        $cycle = LeaveCycle::active();

        // ── Summary stats ────────────────────────────────────────────────────
        $stats = [
            'policy_violations' => PolicyViolation::whereJsonContains('event_snapshot->module', 'Leave')
                ->where('status', 'open')->count(),
            'anomalies'         => LeaveAnomalyFlag::open()
                ->whereNotIn('anomaly_type', [LeaveAnomalyType::PolicyViolation->value, LeaveAnomalyType::BurnoutRisk->value])
                ->count(),
            'liabilities'       => DB::table('leave_balance_transactions')
                ->when($cycle, fn($q) => $q->where('leave_cycle_id', $cycle->id))
                ->where('balance_after', '>', 0)
                ->distinct()->count('employee_id'),
            'burnout_risks'     => LeaveAnomalyFlag::open()
                ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)->count(),
            'compliance_alerts' => PolicyRuleAuditLog::where('evaluation_result', 'blocked')
                ->whereNull('override_reason')->count(),
        ];

        // ── Policy violations (latest 20, open) ──────────────────────────────
        $violations = PolicyViolation::with(['policy', 'rule', 'user'])
            ->whereJsonContains('event_snapshot->module', 'Leave')
            ->latest()->limit(20)->get()
            ->map(fn($v) => [
                'id'          => $v->id,
                'policy_name' => $v->policy?->name ?? 'Unknown',
                'rule_name'   => $v->rule?->name ?? 'Unknown',
                'severity'    => $v->severity,
                'status'      => $v->status,
                'employee'    => $v->user?->name ?? '—',
                'leave_type'  => data_get($v->event_snapshot, 'leave_type_name', '—'),
                'detected_at' => $v->created_at?->format('d M Y H:i'),
                'resolved_at' => $v->resolved_at?->format('d M Y'),
            ]);

        // ── Anomalies (latest 20, excluding policy violation & burnout) ───────
        $anomalies = LeaveAnomalyFlag::with('employee')
            ->whereNotIn('anomaly_type', [LeaveAnomalyType::PolicyViolation->value, LeaveAnomalyType::BurnoutRisk->value])
            ->latest('detected_at')->limit(20)->get()
            ->map(fn($f) => [
                'id'            => $f->id,
                'employee_name' => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'anomaly_type'  => $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : (string) $f->anomaly_type,
                'severity'      => $f->severity ?? 'medium',
                'confidence'    => $f->ai_confidence_score ?? null,
                'detected_at'   => $f->detected_at?->format('d M Y'),
                'status'        => $f->false_positive ? 'resolved' : 'open',
            ]);

        // ── Liabilities (top 20 employees by outstanding balance hours) ───────
        $liabilityRows = DB::table('leave_balance_transactions as lbt')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->when($cycle, fn($q) => $q->where('lbt.leave_cycle_id', $cycle->id))
            ->where('hp.status', 1)
            ->select(
                'lbt.employee_id',
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as employee_name"),
                'lt.name as leave_type',
                'lbt.leave_type_id',
                DB::raw('MAX(lbt.id) as latest_tx_id')
            )
            ->groupBy('lbt.employee_id', 'hp.first_name', 'hp.surname', 'lt.name', 'lbt.leave_type_id')
            ->get()
            ->map(function ($row) {
                $balance = (float) (DB::table('leave_balance_transactions')->where('id', $row->latest_tx_id)->value('balance_after') ?? 0);
                return $balance > 0 ? [
                    'employee_name'  => $row->employee_name,
                    'leave_type'     => $row->leave_type,
                    'balance_hours'  => $balance,
                    'balance_days'   => round($balance / 8, 1),
                ] : null;
            })
            ->filter()
            ->sortByDesc('balance_hours')
            ->take(20)
            ->values();

        // ── Burnout indicators (latest 20) ────────────────────────────────────
        $burnoutRisks = LeaveAnomalyFlag::with('employee')
            ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
            ->latest('detected_at')->limit(20)->get()
            ->map(fn($f) => [
                'id'             => $f->id,
                'employee_name'  => trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')) ?: "Employee #{$f->employee_id}",
                'risk_score'     => $f->ai_confidence_score ?? null,
                'leave_frequency'=> data_get($f->metadata, 'leave_frequency', '—'),
                'days_used'      => data_get($f->metadata, 'days_used', '—'),
                'sick_days'      => data_get($f->metadata, 'sick_days', '—'),
                'flagged_at'     => $f->detected_at?->format('d M Y'),
                'reviewed'       => (bool) $f->reviewed_by,
            ]);

        // ── Compliance alerts (blocked rule evaluations with no override) ─────
        $complianceAlerts = PolicyRuleAuditLog::with(['leaveRequest.employee', 'policyRule'])
            ->where('evaluation_result', 'blocked')
            ->latest('created_at')->limit(20)->get()
            ->map(fn($l) => [
                'id'             => $l->id,
                'rule_code'      => $l->rule_code,
                'detail'         => $l->evaluation_detail,
                'request_number' => $l->leaveRequest?->request_number,
                'employee_name'  => trim(($l->leaveRequest?->employee?->first_name ?? '') . ' ' . ($l->leaveRequest?->employee?->surname ?? '')) ?: '—',
                'ai_explanation' => $l->ai_explanation,
                'created_at'     => $l->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('HR/Leave/HrDashboard', [
            'stats'             => $stats,
            'violations'        => $violations,
            'anomalies'         => $anomalies,
            'liabilities'       => $liabilityRows,
            'burnoutRisks'      => $burnoutRisks,
            'complianceAlerts'  => $complianceAlerts,
        ]);
    }

    public function policyOverrides(): Response
    {
        $overrides = \App\Modules\HR\Leave\Models\LeaveApprovalLevel::where('is_policy_override', true)
            ->with(['leaveRequest.employee', 'leaveRequest.leaveType'])
            ->latest('actioned_at')
            ->limit(200)
            ->get()
            ->map(fn($l) => [
                'id'              => $l->id,
                'request_number'  => $l->leaveRequest?->request_number,
                'employee'        => trim(($l->leaveRequest?->employee?->first_name ?? '') . ' ' . ($l->leaveRequest?->employee?->surname ?? '')),
                'leave_type'      => $l->leaveRequest?->leaveType?->name ?? '—',
                'override_reason' => $l->override_reason,
                'actioned_by'     => $l->approver_id
                    ? \App\Core\User\Models\User::find($l->approver_id)?->name
                    : '—',
                'actioned_at'     => $l->actioned_at?->format('d M Y H:i'),
                'level_name'      => $l->level_name,
            ]);

        $stats = [
            'total'        => $overrides->count(),
            'this_month'   => $overrides->filter(fn($o) => $o['actioned_at'] && \Carbon\Carbon::parse($o['actioned_at'])->isCurrentMonth())->count(),
            'by_leave_type' => $overrides->groupBy('leave_type')->map->count(),
        ];

        return Inertia::render('HR/Leave/PolicyOverrides', [
            'overrides' => $overrides,
            'stats'     => $stats,
        ]);
    }

    public function syncPolicies(): JsonResponse
    {
        $result = $this->policySyncService->sync();

        return response()->json([
            'success'     => true,
            'mapped'      => $result['mapped'],
            'deactivated' => $result['deactivated'],
            'conflicts'   => count($result['conflicts']),
            'message'     => "Sync complete: {$result['mapped']} rule(s) mapped, " . count($result['conflicts']) . " conflict(s) detected.",
        ]);
    }

    public function resolveViolation(Request $request, PolicyViolation $violation): RedirectResponse
    {
        $request->validate(['resolution_note' => 'nullable|string|max:1000']);

        abort_unless($violation->event_snapshot['module'] ?? '' === 'Leave', 404);

        $violation->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        if ($request->resolution_note) {
            $violation->notes()->create([
                'author_id' => Auth::id(),
                'content'   => $request->resolution_note,
                'note_type' => 'resolution',
            ]);
        }

        return back()->with('success', "Violation #{$violation->id} marked as resolved.");
    }

    public function policyImpact(LeaveRequest $leaveRequest): JsonResponse
    {
        $violations = PolicyViolation::with(['policy', 'rule'])
            ->whereJsonContains('event_snapshot->subject_id', $leaveRequest->id)
            ->get()
            ->map(fn($v) => [
                'rule_name'   => $v->rule?->name,
                'policy_name' => $v->policy?->name,
                'severity'    => $v->severity,
                'outcome'     => $v->outcome,
                'explanation' => $v->explanation,
            ])
            ->toArray();

        $auditEntries = PolicyRuleAuditLog::where('leave_request_id', $leaveRequest->id)
            ->with('policyRule')
            ->orderBy('created_at')
            ->get()
            ->map(fn($l) => [
                'rule_code'  => $l->rule_code,
                'result'     => $l->evaluation_result,
                'detail'     => $l->evaluation_detail,
                'ai_used'    => $l->ai_used,
                'created_at' => $l->created_at?->format('d M Y H:i'),
            ])
            ->toArray();

        $aiAnalysis = null;
        try {
            $aiAnalysis = $this->aiAdvisorService->analyzePolicyImpact(
                $this->currentHrPersonId() ?? 0,
                $this->formatRequest($leaveRequest),
                $violations,
                array_column($auditEntries, 'detail'),
            );
        } catch (\Throwable) {}

        return response()->json([
            'violations'    => $violations,
            'audit_entries' => $auditEntries,
            'ai_analysis'   => $aiAnalysis,
        ]);
    }

    // ── Setup: Leave Types ───────────────────────────────────────────────────

    public function leaveTypes(): Response
    {
        $types = LeaveType::withCount('leaveRequests')->orderBy('name')->get();

        return Inertia::render('HR/Leave/Setup/LeaveTypes', [
            'leaveTypes' => $types,
        ]);
    }

    public function storeLeaveType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                       => 'required|string|max:100',
            'statutory_type'             => 'nullable|in:annual,sick,family,family_responsibility,maternity,paternity,parental,unpaid,special,study,overtime,adoption,commissioning,custom',
            'description'                => 'nullable|string|max:500',
            'is_paid'                    => 'boolean',
            'doc_required'               => 'boolean',
            'notes_required'             => 'boolean',
            'requires_medical_certificate' => 'boolean',
            'carry_over_enabled'         => 'boolean',
            'carry_over_max_hours'       => 'nullable|numeric|min:0',
            'accrual_rate_hours_per_month' => 'nullable|numeric|min:0',
            'bcea_minimum_days'          => 'nullable|numeric|min:0',
            'max_consecutive_hours'      => 'nullable|numeric|min:0',
            'gender_restriction'         => 'nullable|in:male,female',
            'prevent_deletion'           => 'boolean',
        ]);

        LeaveType::create(array_merge($data, ['status' => 1]));

        return back()->with('success', 'Leave type created.');
    }

    public function updateLeaveType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        if ($leaveType->prevent_deletion && $request->has('status') && ! $request->status) {
            return back()->withErrors(['error' => 'This statutory leave type cannot be deactivated.']);
        }

        $data = $request->validate([
            'name'                           => 'required|string|max:100',
            'statutory_type'                 => 'nullable|in:annual,sick,family,family_responsibility,maternity,paternity,parental,unpaid,special,study,overtime,adoption,commissioning,custom',
            'description'                    => 'nullable|string|max:500',
            'status'                         => 'boolean',
            'is_paid'                        => 'boolean',
            'doc_required'                   => 'boolean',
            'notes_required'                 => 'boolean',
            'requires_medical_certificate'   => 'boolean',
            'carry_over_enabled'             => 'boolean',
            'carry_over_max_hours'           => 'nullable|numeric|min:0',
            'accrual_rate_hours_per_month'   => 'nullable|numeric|min:0',
            'bcea_minimum_days'              => 'nullable|numeric|min:0',
            'max_consecutive_hours'          => 'nullable|numeric|min:0',
            'require_probation_period_days'  => 'nullable|integer|min:0',
            'gender_restriction'             => 'nullable|in:male,female',
        ]);

        $leaveType->update($data);

        return back()->with('success', 'Leave type updated.');
    }

    // ── Setup: Profiles ──────────────────────────────────────────────────────

    public function profiles(): Response
    {
        $profiles = LeaveProfile::with(['allocations.leaveType'])
            ->orderBy('name')
            ->get()
            ->map(fn($p) => [
                'id'               => $p->id,
                'name'             => $p->name,
                'description'      => $p->description,
                'status'           => (bool) $p->status,
                'employees_count'  => 0,
                'allocations'      => $p->allocations->map(fn($a) => [
                    'id'                          => $a->id,
                    'leave_type_id'               => $a->leave_type_id,
                    'leave_type_name'             => $a->leaveType?->name ?? 'Unknown',
                    'default_hours'               => (float) $a->default_hours,
                    'min_hours'                   => (float) $a->min_hours,
                    'max_hours'                   => (float) $a->max_hours,
                    'carry_over_max_hours'        => $a->carry_over_max_hours ? (float) $a->carry_over_max_hours : null,
                    'accrual_rate_hours_per_month' => $a->accrual_rate_hours_per_month ? (float) $a->accrual_rate_hours_per_month : null,
                    'allow_negative_balance'      => (bool) $a->allow_negative,
                ]),
            ]);

        $leaveTypes = LeaveType::where('status', 1)->get(['id', 'name']);

        return Inertia::render('HR/Leave/Setup/Profiles', [
            'profiles'   => $profiles,
            'leaveTypes' => $leaveTypes,
        ]);
    }

    public function storeProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        LeaveProfile::create(array_merge($data, ['status' => 1]));

        return back()->with('success', 'Leave profile created.');
    }

    public function updateProfile(Request $request, LeaveProfile $profile): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'status'      => 'boolean',
            'allocations' => 'array',
            'allocations.*.leave_type_id'             => 'required|exists:leave_types,id',
            'allocations.*.default_hours'             => 'required|numeric|min:0',
            'allocations.*.max_hours'                 => 'required|numeric|min:0',
            'allocations.*.accrual_rate_hours_per_month' => 'nullable|numeric|min:0',
            'allocations.*.allow_negative'            => 'boolean',
        ]);

        $profile->update(['name' => $data['name'], 'description' => $data['description'], 'status' => $data['status'] ?? $profile->status]);

        if (! empty($data['allocations'])) {
            foreach ($data['allocations'] as $alloc) {
                LeaveProfileAllocation::updateOrCreate(
                    ['leave_profile_id' => $profile->id, 'leave_type_id' => $alloc['leave_type_id']],
                    $alloc + ['leave_profile_id' => $profile->id, 'min_hours' => 0]
                );
            }
        }

        return back()->with('success', 'Leave profile updated.');
    }

    public function storeAllocation(Request $request, LeaveProfile $profile): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id'               => 'required|exists:leave_types,id',
            'default_hours'               => 'required|numeric|min:0',
            'min_hours'                   => 'required|numeric|min:0',
            'max_hours'                   => 'required|numeric|min:0',
            'carry_over_max_hours'        => 'nullable|numeric|min:0',
            'accrual_rate_hours_per_month' => 'nullable|numeric|min:0',
            'allow_negative_balance'      => 'boolean',
        ]);

        $profile->allocations()->create([
            'leave_type_id'               => $data['leave_type_id'],
            'default_hours'               => $data['default_hours'],
            'min_hours'                   => $data['min_hours'],
            'max_hours'                   => $data['max_hours'],
            'carry_over_max_hours'        => $data['carry_over_max_hours'] ?? null,
            'accrual_rate_hours_per_month' => $data['accrual_rate_hours_per_month'] ?? null,
            'allow_negative'              => $data['allow_negative_balance'] ?? false,
            'active'                      => true,
        ]);

        return back()->with('success', 'Allocation added.');
    }

    public function updateAllocation(Request $request, LeaveProfile $profile, LeaveProfileAllocation $allocation): RedirectResponse
    {
        abort_unless($allocation->leave_profile_id === $profile->id, 404);

        $data = $request->validate([
            'default_hours'               => 'required|numeric|min:0',
            'min_hours'                   => 'required|numeric|min:0',
            'max_hours'                   => 'required|numeric|min:0',
            'carry_over_max_hours'        => 'nullable|numeric|min:0',
            'accrual_rate_hours_per_month' => 'nullable|numeric|min:0',
            'allow_negative_balance'      => 'boolean',
        ]);

        $allocation->update([
            'default_hours'               => $data['default_hours'],
            'min_hours'                   => $data['min_hours'],
            'max_hours'                   => $data['max_hours'],
            'carry_over_max_hours'        => $data['carry_over_max_hours'] ?? null,
            'accrual_rate_hours_per_month' => $data['accrual_rate_hours_per_month'] ?? null,
            'allow_negative'              => $data['allow_negative_balance'] ?? false,
        ]);

        return back()->with('success', 'Allocation updated.');
    }

    public function destroyAllocation(LeaveProfile $profile, LeaveProfileAllocation $allocation): RedirectResponse
    {
        abort_unless($allocation->leave_profile_id === $profile->id, 404);

        $allocation->delete();

        return back()->with('success', 'Allocation removed.');
    }

    // ── Setup: Approval Config ───────────────────────────────────────────────

    public function approvalConfig(): Response
    {
        return Inertia::render('HR/Leave/Setup/ApprovalConfig', [
            'config' => LeaveConfiguration::first() ?? new LeaveConfiguration(),
        ]);
    }

    public function updateApprovalConfig(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Escalation timings
            'manager_escalation_days'                      => 'nullable|integer|min:1|max:90',
            'department_escalation_days'                   => 'nullable|integer|min:1|max:90',
            'hr_escalation_days'                           => 'nullable|integer|min:1|max:90',
            'number_of_days_before_automate_application'   => 'nullable|integer|min:0',
            // Delegation
            'allow_delegation'                             => 'boolean',
            // Notifications
            'notify_hr_with_application'                   => 'boolean',
            'notify_employee_about_applications_submitted' => 'boolean',
            'notify_on_reject'                             => 'boolean',
            'notify_on_escalate'                           => 'boolean',
        ]);

        LeaveConfiguration::updateOrCreate([], $data);

        return back()->with('success', 'Approval configuration updated.');
    }

    // ── Setup: Configuration ──────────────────────────────────────────────────

    public function configuration(): Response
    {
        $config = LeaveConfiguration::first() ?? new LeaveConfiguration();

        return Inertia::render('HR/Leave/Setup/Configuration', [
            'config' => $config,
        ]);
    }

    public function updateConfiguration(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Approval workflow
            'require_managers_approval'                      => 'boolean',
            'all_managers_to_approve'                        => 'boolean',
            'require_department_head_approval'               => 'boolean',
            'require_hr_approval'                            => 'boolean',
            'require_payroll_approval'                       => 'boolean',
            'require_delegation_approval'                    => 'boolean',
            'number_of_days_until_escalation'                => 'nullable|integer|min:0',
            'number_of_days_to_remind_manager'               => 'nullable|integer|min:0',
            'number_of_days_before_automate_application'     => 'nullable|integer|min:0',
            // Leave balances
            'allow_annual_leave_credit'                      => 'boolean',
            'allow_sick_leave_credit'                        => 'boolean',
            'allow_annual_negative_days'                     => 'nullable|integer|min:0',
            'allow_sick_negative_days'                       => 'nullable|integer|min:0',
            'allow_unpaid_leave_when_annual_done'            => 'boolean',
            // Documents
            'document_compulsory_on_study_leave_application'  => 'boolean',
            'document_compulsory_when_two_sick_leave_8_weeks' => 'boolean',
            // Notifications
            'notify_hr_with_application'                     => 'boolean',
            'notify_employee_about_applications_submitted'   => 'boolean',
            'preferred_communication_method'                 => 'nullable|integer|in:0,1,2',
            // Access control
            'show_non_employees_in_leave_module'             => 'boolean',
            'limit_administration_to_assigned_divisions'     => 'boolean',
            // Reports
            'balance_as_of_enabled'                          => 'nullable|boolean',
            // Leave Payouts
            'allow_employee_payout_requests'                 => 'nullable|boolean',
            // Birthday Leave
            'birthday_leave_enabled'                         => 'nullable|boolean',
            'birthday_leave_days'                            => 'nullable|numeric|min:0.5|max:30',
        ]);

        // Explicitly capture boolean switches so a `false` value is never silently dropped
        $data['balance_as_of_enabled']           = $request->boolean('balance_as_of_enabled');
        $data['allow_employee_payout_requests']  = $request->boolean('allow_employee_payout_requests');
        $data['birthday_leave_enabled']          = $request->boolean('birthday_leave_enabled');

        // Auto-create the Birthday Leave type when the feature is turned on
        if ($data['birthday_leave_enabled']) {
            \App\Modules\HR\Leave\Models\LeaveType::firstOrCreate(
                ['is_birthday_leave' => true],
                [
                    'name'        => 'Birthday Leave',
                    'status'      => 1,
                    'description' => 'Complimentary leave awarded to employees on their birthday.',
                    'special_leave' => 1,
                    'is_paid'     => true,
                    'prevent_deletion' => true,
                ]
            );
        }

        LeaveConfiguration::updateOrCreate([], $data);

        return back()->with('success', 'Leave configuration updated.');
    }

    // ── Manual Leave Allocation ───────────────────────────────────────────────

    public function manualAllocations(): Response
    {
        $leaveTypes   = LeaveType::where('status', 1)->orderBy('name')->get(['id', 'name']);
        $cycle        = LeaveCycle::active();
        $companyUnits = CompanyUnit::where('active', true)
            ->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type', 'parent_id'])
            ->map(fn($u) => ['id' => $u->id, 'label' => "[{$u->type}] {$u->name}", 'type' => $u->type, 'parent_id' => $u->parent_id]);

        $config = LeaveConfiguration::first();

        return Inertia::render('HR/Leave/ManualAllocations', [
            'leaveTypes'   => $leaveTypes,
            'companyUnits' => $companyUnits,
            'cycle'        => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
        ]);
    }

    public function searchEmployeesForAllocation(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));

        $employees = DB::table('hr_people as hp')
            ->leftJoin('users as u', 'u.id', '=', 'hp.user_id')
            ->leftJoin('company_units as cu1', 'cu1.id', '=', 'hp.division_level_1')
            ->leftJoin('company_units as cu2', 'cu2.id', '=', 'hp.division_level_2')
            ->where('hp.status', 1)
            ->when($q, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->whereRaw("LOWER(TRIM(CONCAT(hp.first_name, ' ', hp.surname))) LIKE ?", ["%{$q}%"])
                      ->orWhereRaw("LOWER(hp.employee_number) LIKE ?", ["%{$q}%"])
                      ->orWhereRaw("LOWER(u.email) LIKE ?", ["%{$q}%"]);
                });
            })
            ->limit(20)
            ->get([
                'hp.id',
                'hp.employee_number',
                DB::raw("TRIM(CONCAT(hp.first_name, ' ', hp.surname)) as full_name"),
                'u.email',
                'cu1.name as division',
                'cu2.name as department',
                'hp.profile_pic',
            ]);

        return response()->json($employees->map(function ($emp) {
            $photo = StoredImage::url($emp->profile_pic_path ?? null, $emp->profile_pic);
            return [
                'id'              => $emp->id,
                'employee_number' => $emp->employee_number,
                'full_name'       => $emp->full_name,
                'email'           => $emp->email,
                'division'        => $emp->division,
                'department'      => $emp->department,
                'photo_url'       => $photo,
            ];
        }));
    }

    public function employeeBalancesForAllocation(int $hrPersonId): JsonResponse
    {
        $cycle    = LeaveCycle::active();
        $balances = $this->balanceService->getAllBalances($hrPersonId);

        return response()->json([
            'balances' => $balances,
            'cycle'    => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
        ]);
    }

    public function adjustSingleBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'   => 'required|integer|exists:hr_people,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'action'        => 'required|in:add,deduct',
            'days'          => 'required|numeric|min:0.5|max:999',
            'reason'        => 'required|string|max:500',
        ]);

        // Applied through the allocation service so the adjustment is recorded as
        // a batch and can be listed and reversed later.
        $batch = app(LeaveManualAllocationService::class)->apply(
            scopeType:   'single',
            scopeId:     null,
            employeeId:  (int) $data['employee_id'],
            leaveTypeId: (int) $data['leave_type_id'],
            action:      $data['action'],
            days:        (float) $data['days'],
            reason:      $data['reason'],
        );

        $newBalance = $this->balanceService->getBalance($data['employee_id'], $data['leave_type_id']);

        activity()
            ->useLog('LeaveManualAllocation')
            ->causedBy(Auth::user())
            ->withProperties([
                'employee_id'   => $data['employee_id'],
                'leave_type_id' => $data['leave_type_id'],
                'action'        => $data['action'],
                'days'          => $data['days'],
                'reason'        => $data['reason'],
                'new_balance'   => $newBalance,
            ])
            ->log("Manual leave balance {$data['action']} of {$data['days']}d for employee #{$data['employee_id']}");

        return response()->json([
            'message'     => 'Balance adjusted successfully.',
            'new_balance' => $newBalance,
            'batch_uuid'  => $batch->uuid,
        ]);
    }

    /**
     * Bulk-applied leave that is still live, so a run submitted by mistake can be
     * withdrawn in one go rather than cancelled request by request.
     */
    public function bulkAppliedLeave(Request $request): JsonResponse
    {
        $requests = LeaveRequest::query()
            ->where('is_bulk', true)
            ->whereNotIn('status', [LeaveRequestStatus::Cancelled->value, LeaveRequestStatus::Rejected->value])
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('start_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('start_date', '<=', $d))
            ->with(['employee:id,first_name,surname,employee_number', 'leaveType:id,name'])
            ->latest('start_date')
            ->paginate(50);

        return response()->json($requests);
    }

    /**
     * Cancel selected bulk-applied leave and put the hours back.
     *
     * Approved requests have already been deducted, so those are released through
     * the balance service; anything still pending never left the balance and just
     * needs its reservation released.
     */
    public function bulkDeleteLeave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_ids'   => 'required|array|min:1',
            'request_ids.*' => 'required|integer|exists:leave_requests,id',
            'reason'        => 'required|string|max:500',
        ]);

        $cancelled = 0;
        $skipped   = [];

        DB::transaction(function () use ($data, &$cancelled, &$skipped) {
            $requests = LeaveRequest::whereIn('id', $data['request_ids'])->where('is_bulk', true)->get();

            foreach ($requests as $leaveRequest) {
                if (in_array($leaveRequest->status, [LeaveRequestStatus::Cancelled->value, LeaveRequestStatus::Rejected->value], true)) {
                    $skipped[] = "{$leaveRequest->request_number} is already {$leaveRequest->status}.";
                    continue;
                }

                if ($leaveRequest->status === LeaveRequestStatus::Approved->value) {
                    $this->balanceService->releaseApproved($leaveRequest);
                } else {
                    $this->balanceService->release($leaveRequest);
                }

                $leaveRequest->forceFill([
                    'status'              => LeaveRequestStatus::Cancelled->value,
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $data['reason'],
                ])->save();

                $cancelled++;
            }
        });

        activity()
            ->useLog('LeaveBulkDelete')
            ->causedBy(Auth::user())
            ->withProperties(['cancelled' => $cancelled, 'reason' => $data['reason']])
            ->log("Bulk-applied leave withdrawn: {$cancelled} request(s)");

        return response()->json([
            'message'   => "{$cancelled} bulk leave request(s) cancelled and balances restored.",
            'cancelled' => $cancelled,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Reset leave balances to zero for a scope, writing off whatever is there.
     *
     * Deliberately preview-first: this is destructive, so the caller sees every
     * affected employee and their current balance before confirming.
     */
    public function previewLeaveReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type'    => 'required|in:single,company_unit',
            'scope_id'      => 'nullable|required_if:scope_type,company_unit|integer|exists:company_units,id',
            'employee_id'   => 'nullable|required_if:scope_type,single|integer|exists:hr_people,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
        ]);

        $service   = app(LeaveManualAllocationService::class);
        $employees = $service->resolveEmployees(
            $data['scope_type'],
            isset($data['scope_id']) ? (int) $data['scope_id'] : null,
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        );

        $rows = $employees->map(function ($employee) use ($data) {
            $balance = (float) $this->balanceService->getBalance($employee->id, (int) $data['leave_type_id']);

            return [
                'employee_id'     => $employee->id,
                'employee_name'   => trim($employee->first_name . ' ' . $employee->surname),
                'employee_number' => $employee->employee_number,
                'balance_before'  => round($balance, 2),
                'days_before'     => round($balance / 8, 2),
                'balance_after'   => 0,
            ];
        })->values();

        return response()->json([
            'rows'   => $rows,
            'totals' => [
                'employees'      => $rows->count(),
                'total_hours'    => round($rows->sum('balance_before'), 2),
                'total_days'     => round($rows->sum('days_before'), 2),
                'with_balance'   => $rows->where('balance_before', '!=', 0)->count(),
            ],
        ]);
    }

    /** Apply the reset previewed above. */
    public function resetLeaveBalances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type'    => 'required|in:single,company_unit',
            'scope_id'      => 'nullable|required_if:scope_type,company_unit|integer|exists:company_units,id',
            'employee_id'   => 'nullable|required_if:scope_type,single|integer|exists:hr_people,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'reason'        => 'required|string|max:500',
        ]);

        $service   = app(LeaveManualAllocationService::class);
        $employees = $service->resolveEmployees(
            $data['scope_type'],
            isset($data['scope_id']) ? (int) $data['scope_id'] : null,
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        );

        $reset = 0;

        DB::transaction(function () use ($employees, $data, &$reset) {
            foreach ($employees as $employee) {
                $this->balanceService->resetBalance(
                    $employee->id,
                    (int) $data['leave_type_id'],
                    $data['reason'],
                );
                $reset++;
            }
        });

        activity()
            ->useLog('LeaveReset')
            ->causedBy(Auth::user())
            ->withProperties([
                'leave_type_id' => $data['leave_type_id'],
                'scope_type'    => $data['scope_type'],
                'affected'      => $reset,
                'reason'        => $data['reason'],
            ])
            ->log("Leave balances reset for {$reset} employee(s)");

        return response()->json([
            'message'  => "Leave balances reset for {$reset} employee(s).",
            'affected' => $reset,
        ]);
    }

    /**
     * What a manual allocation would do, before anything is written: every
     * affected employee with their balance before, the change, and the balance
     * afterwards — including any that would go negative.
     */
    public function previewManualAllocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type'    => 'required|in:single,company_unit',
            'scope_id'      => 'nullable|required_if:scope_type,company_unit|integer|exists:company_units,id',
            'employee_id'   => 'nullable|required_if:scope_type,single|integer|exists:hr_people,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'action'        => 'required|in:add,deduct',
            'days'          => 'required|numeric|min:0.5|max:999',
        ]);

        $preview = app(LeaveManualAllocationService::class)->preview(
            scopeType:   $data['scope_type'],
            scopeId:     isset($data['scope_id']) ? (int) $data['scope_id'] : null,
            employeeId:  isset($data['employee_id']) ? (int) $data['employee_id'] : null,
            leaveTypeId: (int) $data['leave_type_id'],
            action:      $data['action'],
            days:        (float) $data['days'],
        );

        return response()->json($preview);
    }

    /** Past manual allocations, newest first, for review and reversal. */
    public function manualAllocationBatches(Request $request): JsonResponse
    {
        $batches = LeaveManualAllocationBatch::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->withCount('items')
            ->latest('id')
            ->paginate(20);

        return response()->json($batches);
    }

    /** The employees affected by one past allocation. */
    public function manualAllocationBatchItems(LeaveManualAllocationBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $batch,
            'items' => $batch->items()->orderBy('employee_name')->get(),
        ]);
    }

    /**
     * Undo a past allocation. The original ledger entries stay put — the reversal
     * posts opposite movements, so the history of both actions remains visible.
     */
    public function reverseManualAllocation(Request $request, LeaveManualAllocationBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $reversed = app(LeaveManualAllocationService::class)->reverse($batch, $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        activity()
            ->useLog('LeaveManualAllocation')
            ->causedBy(Auth::user())
            ->withProperties([
                'batch_id' => $batch->id,
                'affected' => $reversed,
                'reason'   => $data['reason'] ?? null,
            ])
            ->log("Reversed manual leave allocation batch #{$batch->id} for {$reversed} employee(s)");

        return response()->json([
            'message'  => "Allocation reversed for {$reversed} employee(s).",
            'affected' => $reversed,
        ]);
    }

    public function sendBulkAdjustOtp(Request $request): JsonResponse
    {
        /** @var \App\Core\User\Models\User $user */
        $user  = Auth::user();
        $phone = $user->person?->cell_number;

        if (!$phone) {
            return response()->json(['error' => 'No phone number is registered on your profile. Contact your administrator.'], 422);
        }

        $throttleKey = 'bulk_otp_send:' . $user->id . ':' . $request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            return response()->json(['error' => "Too many OTP requests. Please wait {$seconds} seconds."], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 300);

        $authService = app(AuthService::class);
        $otp         = $authService->generateBulkOtp($user->id);

        $maskedPhone = substr($phone, 0, 3) . str_repeat('*', max(0, strlen($phone) - 5)) . substr($phone, -2);

        SmsService::send($phone, "Your Mkhaya Agri Bulk Balance Adjustment OTP is: {$otp}. Valid for 15 minutes. Do not share.");

        return response()->json([
            'message'      => 'OTP sent to your registered phone number.',
            'phone_masked' => $maskedPhone,
        ]);
    }

    public function bulkAdjustBalances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type'    => 'required|in:company_unit',
            'scope_id'      => 'required|integer|exists:company_units,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'action'        => 'required|in:add,deduct',
            'days'          => 'required|numeric|min:0.5|max:999',
            'reason'        => 'required|string|max:500',
            'otp'           => 'required|digits:6',
        ]);

        /** @var \App\Core\User\Models\User $authUser */
        $authUser    = Auth::user();
        $authService = app(AuthService::class);

        try {
            $authService->verifyBulkOtp($authUser->id, $data['otp']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Resolve employees in the selected unit (and any child units)
        $unitIds = $this->getAllChildUnitIds((int) $data['scope_id']);

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->where(function ($q) use ($unitIds) {
                $q->whereIn('division_level_1', $unitIds)
                  ->orWhereIn('division_level_2', $unitIds)
                  ->orWhereIn('division_level_3', $unitIds)
                  ->orWhereIn('division_level_4', $unitIds)
                  ->orWhereIn('division_level_5', $unitIds)
                  ->orWhereIn('user_id', function ($sub) use ($unitIds) {
                      $sub->select('user_id')
                          ->from('company_unit_user_pivots')
                          ->whereIn('company_id', $unitIds);
                  });
            })
            ->pluck('id')
            ->unique();

        if ($employees->isEmpty()) {
            return response()->json(['error' => 'No active employees found in the selected unit.'], 422);
        }

        // Applied through the allocation service so the whole run is recorded as a
        // single reversible batch rather than a scatter of untraceable ledger rows.
        try {
            $batch = app(LeaveManualAllocationService::class)->apply(
                scopeType:   'company_unit',
                scopeId:     (int) $data['scope_id'],
                employeeId:  null,
                leaveTypeId: (int) $data['leave_type_id'],
                action:      $data['action'],
                days:        (float) $data['days'],
                reason:      "Bulk {$data['action']}: {$data['reason']}",
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $count    = $batch->total_employees;
        $unitName = CompanyUnit::find($data['scope_id'])?->name ?? 'Unknown';

        activity()
            ->useLog('LeaveManualAllocation')
            ->causedBy(Auth::user())
            ->withProperties([
                'scope'         => $unitName,
                'leave_type_id' => $data['leave_type_id'],
                'action'        => $data['action'],
                'days'          => $data['days'],
                'reason'        => $data['reason'],
                'affected'      => $count,
            ])
            ->log("Bulk leave balance {$data['action']} of {$data['days']}d applied to {$count} employees in {$unitName}");

        return response()->json([
            'message'    => "{$count} employee(s) updated successfully.",
            'affected'   => $count,
            'batch_uuid' => $batch->uuid,
        ]);
    }

    public function previewBulkScope(Request $request): JsonResponse
    {
        $request->validate([
            'scope_id' => 'required|integer|exists:company_units,id',
        ]);

        $unitIds   = $this->getAllChildUnitIds((int) $request->scope_id);
        $unitName  = CompanyUnit::find($request->scope_id)?->name ?? '';

        $count = DB::table('hr_people')
            ->where('status', 1)
            ->where(function ($q) use ($unitIds) {
                $q->whereIn('division_level_1', $unitIds)
                  ->orWhereIn('division_level_2', $unitIds)
                  ->orWhereIn('division_level_3', $unitIds)
                  ->orWhereIn('division_level_4', $unitIds)
                  ->orWhereIn('division_level_5', $unitIds)
                  ->orWhereIn('user_id', function ($sub) use ($unitIds) {
                      $sub->select('user_id')
                          ->from('company_unit_user_pivots')
                          ->whereIn('company_id', $unitIds);
                  });
            })
            ->count();

        return response()->json(['count' => $count, 'unit_name' => $unitName]);
    }

    private function getAllChildUnitIds(int $parentId): array
    {
        $ids = [$parentId];
        $children = CompanyUnit::where('parent_id', $parentId)->pluck('id')->toArray();
        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getAllChildUnitIds($childId));
        }
        return array_unique($ids);
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    public function uploadDocument(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $request->validate([
            'document'      => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'document_type' => 'required|in:medical_certificate,supporting_doc,birth_certificate,other',
        ]);

        $this->policyGuard->forDocumentUpload($leaveRequest, $request->document_type, Auth::id());

        $doc   = new \App\Modules\HR\Leave\Models\LeaveDocument();
        $saved = $doc->saveFileToDatabase($request->file('document'));

        $document = $leaveRequest->documents()->make([
            'uploader_id'       => Auth::id(),
            'document_type'     => $request->document_type,
            'original_filename' => $saved['original_name'],
            'disk'              => 'database',
            'file_size_bytes'   => $saved['size'],
            'mime_type'         => $saved['mime'],
            'is_encrypted'      => false,
            'uploaded_at'       => now(),
        ]);

            // The bytes go to the shared disk. `path` held the whole JSON envelope despite
            // its name, and is left alone until these rows are pruned.
            $document->writeBytes(base64_decode($saved['data']), pathinfo((string) $saved['original_name'], PATHINFO_EXTENSION));
            $document->save();

        return back()->with('success', 'Document uploaded successfully.');
    }

    public function viewDocument(LeaveRequest $leaveRequest, \App\Modules\HR\Leave\Models\LeaveDocument $document): \Illuminate\Http\Response
    {
        abort_unless($document->leave_request_id === $leaveRequest->id, 404);

        return $document->toResponse(inline: true);
    }

    public function downloadDocument(LeaveRequest $leaveRequest, \App\Modules\HR\Leave\Models\LeaveDocument $document): \Illuminate\Http\Response
    {
        abort_unless($document->leave_request_id === $leaveRequest->id, 404);

        return $document->toResponse(inline: false);
    }

    // ── Scheduled Reports ────────────────────────────────────────────────────

    public function scheduledReports(): Response
    {
        $reports = LeaveScheduledReport::orderBy('cadence')->orderBy('name')->get()
            ->map(fn($r) => $this->formatScheduledReport($r));

        return Inertia::render('HR/Leave/Setup/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->availableReportKeys(),
        ]);
    }

    public function storeScheduledReport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);

        $report = new LeaveScheduledReport($data);
        $report->created_by = Auth::id();
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function updateScheduledReport(Request $request, LeaveScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroyScheduledReport(LeaveScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();

        return back()->with('success', 'Scheduled report deleted.');
    }

    private function formatScheduledReport(LeaveScheduledReport $r): array
    {
        $nextRun = $r->next_run_at;
        $nextRunLabel = $nextRun ? $nextRun->format('D d M Y H:i') : '—';

        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => ucfirst($r->cadence),
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $nextRunLabel,
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
        ];
    }

    private function availableReportKeys(): array
    {
        return [
            'balance'     => 'Leave Balance',
            'taken'       => 'Leave Taken',
            'pending'     => 'Pending Leave',
            'transaction' => 'Transaction Ledger',
            'allowance'   => 'Allowance Report',
            'payout'      => 'Paid-Out Leave',
            'cancelled'   => 'Cancelled Leave',
            'violation'   => 'Policy Violations',
            'anomaly'     => 'AI Anomalies',
            'burnout'     => 'Burnout Risk',
            'trend'       => 'Department Trends',
            'liability'   => 'Leave Liability',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Adds actual_balance_hours/days and future_committed_hours/days to each balance row.
     * Actual Balance = Current Balance + hours locked in future (not-yet-started) active leaves.
     */
    private function enrichWithActualBalance(\Illuminate\Support\Collection $balances, int $hrPersonId): \Illuminate\Support\Collection
    {
        $futureHours = $this->balanceService->getFutureCommittedHours($hrPersonId);
        return $balances->map(function (array $b) use ($futureHours) {
            $future      = (float) ($futureHours[$b['leave_type_id']] ?? 0);
            $actualHours = $b['balance_hours'] + $future;
            return array_merge($b, [
                'actual_balance_hours'    => $actualHours,
                'actual_balance_days'     => round($actualHours / 8, 2),
                'future_committed_hours'  => $future,
                'future_committed_days'   => round($future / 8, 2),
            ]);
        });
    }

    private function currentHrPersonId(): ?int
    {
        return DB::table('hr_people')
            ->where('user_id', Auth::id())
            ->value('id');
    }

    private function formatRequest(LeaveRequest $r): array
    {
        return [
            'id'             => $r->id,
            'uuid'           => $r->uuid,
            'request_number' => $r->request_number,
            'employee_id'    => $r->employee_id,
            'employee_name'  => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')) ?: "Employee #{$r->employee_id}",
            'leave_type'     => $r->leaveType?->name ?? 'Unknown',
            'start_date'     => $r->start_date?->format('d M Y'),
            'end_date'       => $r->end_date?->format('d M Y'),
            'request_mode'   => $r->request_mode ?? 'daily',
            'start_time'     => $r->start_time,
            'end_time'       => $r->end_time,
            'working_days'   => $r->working_days,
            'working_hours'  => $r->working_hours,
            'status'         => $r->status?->value ?? $r->status,
            'status_label'   => $r->status?->label() ?? ucfirst((string) $r->status),
            'status_color'   => $r->status?->color() ?? 'secondary',
            'submitted_at'   => $r->submitted_at?->format('d M Y H:i'),
            'notes'          => $r->notes,
        ];
    }

    private function formatRequestDetail(LeaveRequest $r): array
    {
        return array_merge($this->formatRequest($r), [
            'ai_explanation'    => $r->ai_explanation,
            'rejection_reason'  => $r->rejection_reason,
            'cancellation_reason' => $r->cancellation_reason,
            'bcea_validation'   => $r->bcea_validation_result,
            'approval_chain'    => $r->approvalLevels->map(fn($l) => [
                'level'      => $l->level,
                'level_name' => $l->level_name,
                'status'     => $l->status,
                'actioned_at' => $l->actioned_at?->format('d M Y H:i'),
                'comment'    => $l->comment,
            ]),
            'documents' => $r->documents->map(fn($d) => [
                'id'            => $d->id,
                'document_type' => $d->document_type,
                'filename'      => $d->original_filename,
                'uploaded_at'   => $d->uploaded_at?->format('d M Y'),
                'view_url'      => route('hr.leave.documents.view', [$r->id, $d->id]),
                'download_url'  => route('hr.leave.documents.download', [$r->id, $d->id]),
            ]),
        ]);
    }

    private function leaveTypeColor(?string $statutoryType): string
    {
        return match($statutoryType) {
            'annual'    => '#4CAF50',
            'sick'      => '#F44336',
            'maternity',
            'paternity',
            'parental'  => '#9C27B0',
            'study'     => '#2196F3',
            'special'   => '#FF9800',
            default     => '#607D8B',
        };
    }
}
