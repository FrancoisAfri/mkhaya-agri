<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use App\Modules\PerformanceAppraisal\Trait\ReportsHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PaAppraisalReviewController extends Controller
{
    use ReportsHierarchy;

    public function __construct(private PerformanceAppraisalPolicyGuard $policyGuard) {}

    // ── Manager: list pending reviews ──────────────────────────────────────────

    public function index(): Response
    {
        /** @var User $user */
        $user    = Auth::user();
        $allowed = $this->getAllowedEmployeeIds($user);

        $pending = PaPerformanceSnapshot::with(['employee.person', 'cycle', 'approver.person'])
            ->when($allowed !== null, fn($q) => $q->whereIn('employee_id', $allowed))
            ->whereIn('appraisal_status', ['employee_submitted', 'manager_review'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn($s) => $this->formatSnapshot($s));

        $approved = PaPerformanceSnapshot::with(['employee.person', 'cycle', 'approver.person'])
            ->when($allowed !== null, fn($q) => $q->whereIn('employee_id', $allowed))
            ->where('appraisal_status', 'approved')
            ->orderByDesc('approved_at')
            ->limit(50)
            ->get()
            ->map(fn($s) => $this->formatSnapshot($s));

        // Employee's own snapshots (for self-assessment submission)
        $mySnapshots = PaPerformanceSnapshot::with(['cycle', 'approver.person'])
            ->where('employee_id', $user->id)
            ->orderByDesc('snapshot_date')
            ->limit(20)
            ->get()
            ->map(fn($s) => $this->formatSnapshot($s));

        return Inertia::render('Modules/PerformanceAppraisal/AppraisalReview/Index', [
            'pending'     => $pending,
            'approved'    => $approved,
            'mySnapshots' => $mySnapshots,
            'isManager'   => $allowed !== null,
        ]);
    }

    // ── Employee: submit self-assessment ───────────────────────────────────────

    public function submit(Request $request, PaPerformanceSnapshot $snapshot): RedirectResponse
    {
        abort_unless($snapshot->employee_id === Auth::id(), 403);
        abort_if(in_array($snapshot->appraisal_status, ['approved']), 422, 'Appraisal already approved.');

        $data = $request->validate([
            'employee_score'    => ['required', 'numeric', 'min:0', 'max:100'],
            'employee_comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $snapshot->update([
            'employee_score'    => $data['employee_score'],
            'employee_comments' => $data['employee_comments'] ?? null,
            'appraisal_status'  => 'employee_submitted',
            'submitted_at'      => now(),
        ]);

        $this->policyGuard->forAppraisalSubmitted($snapshot->fresh(), Auth::id());

        return back()->with('success', 'Appraisal submitted for manager review.');
    }

    // ── Manager: enter their score + comments ──────────────────────────────────

    public function managerScore(Request $request, PaPerformanceSnapshot $snapshot): RedirectResponse
    {
        $this->assertManagerAccess($snapshot);

        $data = $request->validate([
            'manager_score'    => ['required', 'numeric', 'min:0', 'max:100'],
            'score_360'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manager_comments' => ['nullable', 'string', 'max:2000'],
        ]);

        // Compute final score: weighted average (KPI auto-score 50%, manager 30%, 360 20%)
        $kpiScore = $snapshot->overall_score ?? 0;
        $mgrScore = $data['manager_score'];
        $s360     = $data['score_360'] ?? $this->compute360Score($snapshot);

        $finalScore = $s360 !== null
            ? round($kpiScore * 0.50 + $mgrScore * 0.30 + $s360 * 0.20, 2)
            : round($kpiScore * 0.60 + $mgrScore * 0.40, 2);

        $snapshot->update([
            'manager_score'    => $mgrScore,
            'score_360'        => $s360,
            'final_score'      => $finalScore,
            'manager_comments' => $data['manager_comments'] ?? null,
            'appraisal_status' => 'manager_review',
        ]);

        return back()->with('success', 'Score saved. Ready to approve.');
    }

    // ── Manager: approve ───────────────────────────────────────────────────────

    public function approve(PaPerformanceSnapshot $snapshot): RedirectResponse
    {
        $this->assertManagerAccess($snapshot);
        abort_if($snapshot->manager_score === null, 422, 'Enter a manager score before approving.');

        $snapshot->update([
            'appraisal_status' => 'approved',
            'approved_by'      => Auth::id(),
            'approved_at'      => now(),
        ]);

        $this->policyGuard->forAppraisalApproved($snapshot->fresh(), Auth::id());

        return back()->with('success', 'Appraisal approved.');
    }

    // ── Manager: reject / send back ────────────────────────────────────────────

    public function reject(Request $request, PaPerformanceSnapshot $snapshot): RedirectResponse
    {
        $this->assertManagerAccess($snapshot);

        $data = $request->validate(['manager_comments' => ['required', 'string']]);

        $snapshot->update([
            'appraisal_status' => 'draft',
            'manager_comments' => $data['manager_comments'],
        ]);

        return back()->with('success', 'Appraisal returned to employee.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertManagerAccess(PaPerformanceSnapshot $snapshot): void
    {
        /** @var User $user */
        $user    = Auth::user();
        $allowed = $this->getAllowedEmployeeIds($user);

        if ($allowed !== null && !in_array($snapshot->employee_id, $allowed)) {
            abort(403, 'You do not have access to this appraisal.');
        }
    }

    private function compute360Score(PaPerformanceSnapshot $snapshot): ?float
    {
        $reviews = Pa360Review::where('employee_id', $snapshot->employee_id)
            ->where('cycle_id', $snapshot->cycle_id)
            ->whereNotNull('overall_score')
            ->get();

        if ($reviews->isEmpty()) return null;

        return round($reviews->avg('overall_score') * 20, 2); // normalise 0–5 to 0–100
    }

    private function formatSnapshot(PaPerformanceSnapshot $s): array
    {
        $emp  = $s->employee;
        $person = $emp?->person;
        $name = $person ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) : ($emp?->email ?? '—');

        return [
            'id'                => $s->id,
            'employee_name'     => $name ?: ($emp?->email ?? '—'),
            'employee_id'       => $s->employee_id,
            'cycle_name'        => $s->cycle?->name,
            'cycle_id'          => $s->cycle_id,
            'snapshot_date'     => $s->snapshot_date?->format('d M Y'),
            'overall_score'     => $s->overall_score,      // KPI auto-score
            'employee_score'    => $s->employee_score,
            'manager_score'     => $s->manager_score,
            'score_360'         => $s->score_360,
            'final_score'       => $s->final_score,
            'appraisal_status'  => $s->appraisal_status,
            'employee_comments' => $s->employee_comments,
            'manager_comments'  => $s->manager_comments,
            'submitted_at'      => $s->submitted_at?->format('d M Y'),
            'approved_at'       => $s->approved_at?->format('d M Y'),
            'approved_by'       => $s->approver ? (trim(($s->approver->person?->first_name ?? '') . ' ' . ($s->approver->person?->surname ?? '')) ?: $s->approver->email) : null,
        ];
    }
}
