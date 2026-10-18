<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaGoal;
use App\Modules\PerformanceAppraisal\Models\PaKpiEvidence;
use App\Modules\PerformanceAppraisal\Notifications\PaKpiAssignedNotification;
use App\Modules\PerformanceAppraisal\Services\PaKpiScoreService;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PaKpiController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use FileStorage, SafeNotifies;

    public function __construct(
        private PaKpiScoreService $scorer,
        private PerformanceAppraisalPolicyGuard $policyGuard,
    ) {}

    // ── Employee KPIs index ───────────────────────────────────────────────────

    public function index(Request $request): InertiaResponse
    {
        $cycleId = $request->query('cycle_id');

        return Inertia::render('Modules/PerformanceAppraisal/KPIs/Index', [
            'kpis'     => PaEmployeeKpi::with(['employee', 'goal', 'score'])
                ->withCount('progressLogs')
                ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
                ->orderBy('pa_employee_kpis.created_at', 'desc')
                ->get()
                ->map(fn($k) => [
                    'id'                    => $k->id,
                    'cycle_id'              => $k->cycle_id,
                    'employee_id'           => $k->employee_id,
                    'employee_name'         => $k->employee->email ?? '-',
                    'goal_id'               => $k->goal_id,
                    'goal_title'            => $k->goal?->title,
                    'title'                 => $k->title,
                    'description'           => $k->description,
                    'target_value'          => $k->target_value,
                    'unit'                  => $k->unit,
                    'weighting'             => $k->weighting,
                    'self_update_allowed'   => $k->self_update_allowed,
                    'manager_update_allowed'=> $k->manager_update_allowed,
                    'status'                => $k->status,
                    'latest_value'          => $k->latestValue(),
                    'score_pct'             => $k->score?->score_pct,
                    'weighted_score'        => $k->score?->weighted_score,
                    'progress_logs_count'   => $k->progress_logs_count,
                ]),
            'cycles'   => PaCycle::orderByDesc('start_date')->get(['id', 'name', 'status']),
            'goals'    => PaGoal::where('status', '!=', 'cancelled')->orderBy('title')->get(['id', 'title', 'cycle_id']),
            'employees'=> $this->employeeList(),
            'selectedCycleId' => $cycleId ? (int) $cycleId : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        // Prevent duplicate goal for same employee + cycle
        if (!empty($data['goal_id'])) {
            $goalExists = PaEmployeeKpi::where('employee_id', $data['employee_id'])
                ->where('cycle_id', $data['cycle_id'])
                ->where('goal_id', $data['goal_id'])
                ->exists();
            if ($goalExists) {
                return back()->withErrors(['goal_id' => 'This goal is already assigned to this employee for the selected cycle.']);
            }
        }

        if (!empty($data['weighting'])) {
            $used = PaEmployeeKpi::where('employee_id', $data['employee_id'])
                ->where('cycle_id', $data['cycle_id'])
                ->sum('weighting');
            if ($used + $data['weighting'] > 100) {
                return back()->withErrors(['weighting' => "Total KPI weighting cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $kpi = PaEmployeeKpi::create($data);

        // Notify the employee (skip self-assignment)
        if ($kpi->employee_id !== $request->user()->id) {
            $employee = User::find($kpi->employee_id);
            if ($employee) {
                $this->safeNotify($employee, new PaKpiAssignedNotification($kpi));
            }
        }

        return back()->with('success', 'KPI created.');
    }

    public function update(Request $request, PaEmployeeKpi $kpi): RedirectResponse
    {
        $data = $this->validated($request, isUpdate: true);

        // Prevent duplicate goal for same employee + cycle (excluding self)
        if (!empty($data['goal_id'])) {
            $goalExists = PaEmployeeKpi::where('employee_id', $kpi->employee_id)
                ->where('cycle_id', $kpi->cycle_id)
                ->where('goal_id', $data['goal_id'])
                ->where('id', '!=', $kpi->id)
                ->exists();
            if ($goalExists) {
                return back()->withErrors(['goal_id' => 'This goal is already assigned to this employee for the selected cycle.']);
            }
        }

        if (!empty($data['weighting'])) {
            $used = PaEmployeeKpi::where('employee_id', $kpi->employee_id)
                ->where('cycle_id', $kpi->cycle_id)
                ->where('id', '!=', $kpi->id)
                ->sum('weighting');
            if ($used + $data['weighting'] > 100) {
                return back()->withErrors(['weighting' => "Total KPI weighting cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $kpi->update($data);

        return back()->with('success', 'KPI updated.');
    }

    public function destroy(PaEmployeeKpi $kpi): RedirectResponse
    {
        $kpi->delete();

        return back()->with('success', 'KPI deleted.');
    }

    // ── Progress logging ──────────────────────────────────────────────────────

    public function logProgress(Request $request, PaEmployeeKpi $kpi): RedirectResponse
    {
        $data = $request->validate([
            'value'    => ['required', 'numeric'],
            'notes'    => ['nullable', 'string'],
            'log_date' => ['required', 'date'],
        ]);
        $data['updated_by'] = $request->user()->id;

        $kpi->progressLogs()->create($data);

        return back()->with('success', 'Progress logged.');
    }

    public function progressHistory(PaEmployeeKpi $kpi): InertiaResponse
    {
        return Inertia::render('Modules/PerformanceAppraisal/KPIs/Progress', [
            'kpi'      => $kpi->load(['employee', 'goal', 'score']),
            'logs'     => $kpi->progressLogs()->with('updatedBy')->orderByDesc('log_date')->get()->map(fn($l) => [
                'id'         => $l->id,
                'value'      => $l->value,
                'notes'      => $l->notes,
                'log_date'   => $l->log_date->format('Y-m-d'),
                'updated_by' => $l->updatedBy->email ?? '-',
            ]),
            'evidence' => $kpi->evidence()->with('uploader')->orderByDesc('created_at')->get()->map(fn($e) => [
                'id'          => $e->id,
                'file_name'   => $e->file_name,
                'mime'        => $e->mime,
                'size'        => $e->size,
                'description' => $e->description,
                'uploaded_by' => $e->uploader->email ?? '-',
                'created_at'  => $e->created_at->format('d M Y'),
            ]),
        ]);
    }

    // ── Evidence uploads ──────────────────────────────────────────────────────

    public function uploadEvidence(Request $request, PaEmployeeKpi $kpi): RedirectResponse
    {
        $request->validate([
            'file'        => ['required', 'file', 'max:10240'],
            'description' => ['nullable', 'string'],
        ]);

        $fileData = $this->saveFileToDatabase($request->file('file'));

        // Spread minus the bytes: the array from the upload trait carries a `data` key that used
        // to be mass-assigned straight into the row.
        $evidence = $kpi->evidence()->make([
            ...\Illuminate\Support\Arr::except($fileData, ['data']),
            'file_name'   => $fileData['original_name'],
            'description' => $request->input('description'),
            'uploaded_by' => $request->user()->id,
        ]);

        $evidence->writeBytes(base64_decode($fileData['data']), pathinfo((string) $fileData['original_name'], PATHINFO_EXTENSION));
        $evidence->save();

        return back()->with('success', 'Evidence uploaded.');
    }

    public function downloadEvidence(PaKpiEvidence $evidence): Response
    {
        return $evidence->buildDownloadResponse();
    }

    public function destroyEvidence(PaKpiEvidence $evidence): RedirectResponse
    {
        $evidence->delete();

        return back()->with('success', 'Evidence removed.');
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    public function scoreKpi(Request $request, PaEmployeeKpi $kpi): RedirectResponse
    {
        $score = $this->scorer->calculateKpiScore($kpi, $request->user()->id);
        $this->policyGuard->forKpiScored($kpi, $score, $request->user()->id);

        return back()->with('success', "KPI scored: {$score->score_pct}% achievement.");
    }

    public function scoreEmployee(Request $request, PaCycle $cycle): RedirectResponse
    {
        $employeeId = $request->validate(['employee_id' => ['required', 'exists:users,id']])['employee_id'];
        $result = $this->scorer->scoreEmployeeCycle($employeeId, $cycle, $request->user()->id);

        return back()->with('success', "Employee scored. Weighted total: {$result['overall_score']}%.");
    }

    public function completionReport(PaCycle $cycle): InertiaResponse
    {
        return Inertia::render('Modules/PerformanceAppraisal/KPIs/CompletionReport', [
            'cycle'  => $cycle->only('id', 'name', 'cycle_type', 'status'),
            'report' => $this->scorer->generateKpiCompletionReport($cycle),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validated(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'cycle_id'               => [$required, 'exists:pa_cycles,id'],
            'employee_id'            => [$required, 'exists:users,id'],
            'goal_id'                => ['nullable', 'exists:pa_goals,id'],
            'title'                  => [$required, 'string', 'max:200'],
            'description'            => ['nullable', 'string'],
            'target_value'           => ['nullable', 'numeric'],
            'unit'                   => ['nullable', 'string', 'max:50'],
            'weighting'              => ['numeric', 'min:0', 'max:100'],
            'self_update_allowed'    => ['boolean'],
            'manager_update_allowed' => ['boolean'],
            'status'                 => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
        ]);
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

    private function employeeList(): \Illuminate\Support\Collection

    {

        return collect($this->tenantPeopleOptions());

    }
}
