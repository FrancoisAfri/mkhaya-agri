<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Notifications\PaFeedbackNotification;
use App\Modules\PerformanceAppraisal\Services\PaFeedbackSummaryService;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use App\Modules\PerformanceAppraisal\Trait\ReportsHierarchy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaFeedbackController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use SafeNotifies, ReportsHierarchy;

    public function __construct(
        private PaFeedbackSummaryService $summariser,
        private PerformanceAppraisalPolicyGuard $policyGuard,
    ) {}

    public function index(Request $request): Response
    {
        $user    = $request->user();
        $cycleId = $request->query('cycle_id');
        $allowed = $this->getAllowedEmployeeIds($user);

        // Clamp the requested employee_id to what the current user may view
        $requestedId = (int) $request->query('employee_id', $user->id);
        $employeeId  = ($allowed === null || in_array($requestedId, $allowed))
            ? $requestedId
            : $user->id;

        $feedback = PaFeedback::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->where('is_draft', false)
            ->with(['author', 'cycle'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($f) => [
                'id'            => $f->id,
                'feedback_type' => $f->feedback_type,
                'content'       => $f->content,
                'rating'        => $f->rating,
                'is_anonymous'  => $f->is_anonymous,
                'author_name'   => $f->author_name,
                'cycle_name'    => $f->cycle?->name,
                'acknowledged_at' => $f->acknowledged_at?->format('d M Y'),
                'created_at'    => $f->created_at->format('d M Y H:i'),
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Feedback/Index', [
            'feedback'         => $feedback,
            'cycles'           => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees'        => $this->employeeList($allowed),
            'selectedEmployee' => $employeeId,
            'selectedCycle'    => $cycleId ? (int) $cycleId : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'   => ['required', 'exists:users,id'],
            'cycle_id'      => ['nullable', 'exists:pa_cycles,id'],
            'feedback_type' => ['required', 'in:manager,peer,upward,self'],
            'content'       => ['required', 'string', 'min:20'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:5'],
            'is_anonymous'  => ['boolean'],
            'is_draft'      => ['boolean'],
        ]);

        $data['given_by'] = $request->user()->id;

        // Self-reflection can only be about yourself
        if ($data['feedback_type'] === 'self') {
            $data['employee_id'] = $request->user()->id;
        }

        $feedback = PaFeedback::create($data);

        if (!($data['is_draft'] ?? false)) {
            $this->policyGuard->forFeedbackSubmitted($feedback, $request->user()->id);
        }

        // Notify recipient only when not a draft and not self-feedback
        if (!($data['is_draft'] ?? false) && $feedback->employee_id !== $request->user()->id) {
            $employee = User::find($feedback->employee_id);
            if ($employee) {
                $this->safeNotify($employee, new PaFeedbackNotification($feedback));
            }
        }

        return back()->with('success', 'Feedback submitted.');
    }

    public function update(Request $request, PaFeedback $feedback): RedirectResponse
    {
        if ($feedback->given_by !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'content'      => ['sometimes', 'string', 'min:20'],
            'rating'       => ['nullable', 'numeric', 'min:0', 'max:5'],
            'is_anonymous' => ['boolean'],
            'is_draft'     => ['boolean'],
        ]);

        $feedback->update($data);

        return back()->with('success', 'Feedback updated.');
    }

    public function destroy(PaFeedback $feedback): RedirectResponse
    {
        $feedback->delete();

        return back()->with('success', 'Feedback deleted.');
    }

    public function acknowledge(PaFeedback $feedback): RedirectResponse
    {
        $feedback->update(['acknowledged_at' => now()]);

        return back()->with('success', 'Feedback acknowledged.');
    }

    public function summary(Request $request): JsonResponse
    {
        $employeeId = $request->validate(['employee_id' => ['required', 'exists:users,id']])['employee_id'];
        $cycleId    = $request->query('cycle_id');

        $allowed = $this->getAllowedEmployeeIds($request->user());
        if ($allowed !== null && !in_array((int) $employeeId, $allowed)) {
            abort(403);
        }

        $text = $this->summariser->generateSummary($employeeId, $cycleId ? (int) $cycleId : null);

        return response()->json(['summary' => $text]);
    }

    /**
     * Everybody this tenant may choose between, optionally narrowed to a set of ids.
     */
    private function employeeList(?array $allowedIds = null): \Illuminate\Support\Collection
    {
        return collect($this->tenantPeopleOptions())
            ->when($allowedIds !== null, fn ($people) => $people->whereIn('id', array_map('intval', $allowedIds)))
            ->values();
    }
}
