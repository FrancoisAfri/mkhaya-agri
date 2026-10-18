<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaProbationCheckin;
use App\Modules\PerformanceAppraisal\Models\PaProbationFeedback;
use App\Modules\PerformanceAppraisal\Models\PaProbationObjective;
use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use App\Modules\PerformanceAppraisal\Notifications\PaProbationCheckinNotification;
use App\Modules\PerformanceAppraisal\Notifications\PaProbationFeedbackNotification;
use App\Modules\PerformanceAppraisal\Notifications\PaProbationStartedNotification;
use App\Modules\PerformanceAppraisal\Services\PaProbationRiskService;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaProbationController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use SafeNotifies;

    public function __construct(
        private PaProbationRiskService $riskService,
        private PerformanceAppraisalPolicyGuard $policyGuard,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Probation/Index', [
            'reviews'   => PaProbationReview::with(['employee', 'manager', 'cycle'])
                ->withCount(['objectives', 'checkins', 'feedback'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($r) => [
                    'id'                   => $r->id,
                    'employee_name'        => $this->personName($r->employee),
                    'manager_name'         => $this->personName($r->manager),
                    'cycle_name'           => $r->cycle?->name,
                    'probation_type'       => $r->probation_type,
                    'start_date'           => $r->start_date->format('Y-m-d'),
                    'end_date'             => $r->end_date->format('Y-m-d'),
                    'status'               => $r->status,
                    'recommendation'       => $r->recommendation,
                    'risk_score'           => $r->risk_score,
                    'risk_level'           => $r->risk_level,
                    'success_probability'  => $r->success_probability,
                    'days_remaining'       => $r->daysRemaining(),
                    'percent_elapsed'      => $r->percentElapsed(),
                    'objectives_count'     => $r->objectives_count,
                    'checkins_count'       => $r->checkins_count,
                    'feedback_count'       => $r->feedback_count,
                ]),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'    => ['required', 'exists:users,id'],
            'manager_id'     => ['required', 'exists:users,id'],
            'cycle_id'       => ['nullable', 'exists:pa_cycles,id'],
            'probation_type' => ['required', 'in:3_month,6_month,custom'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['required', 'date', 'after:start_date'],
            'notes'          => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;

        $review = PaProbationReview::create($data);

        // Notify the employee (skip self-assignment)
        if ($review->employee_id !== $request->user()->id) {
            $employee = User::find($review->employee_id);
            if ($employee) {
                $this->safeNotify($employee, new PaProbationStartedNotification($review));
            }
        }

        return back()->with('success', 'Probation review created.');
    }

    public function update(Request $request, PaProbationReview $review): RedirectResponse
    {
        $data = $request->validate([
            'probation_type' => ['sometimes', 'in:3_month,6_month,custom'],
            'start_date'     => ['sometimes', 'date'],
            'end_date'       => ['sometimes', 'date'],
            'status'         => ['sometimes', 'in:active,extended,confirmed,terminated'],
            'recommendation' => ['nullable', 'in:confirm,extend,terminate'],
            'notes'          => ['nullable', 'string'],
        ]);

        $review->update($data);

        return back()->with('success', 'Probation review updated.');
    }

    public function destroy(PaProbationReview $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', 'Probation review deleted.');
    }

    // ── Single review page ────────────────────────────────────────────────────

    public function show(PaProbationReview $review): Response
    {
        $review->load(['employee', 'manager', 'cycle', 'objectives', 'checkins', 'feedback.author', 'riskScores']);

        return Inertia::render('Modules/PerformanceAppraisal/Probation/Show', [
            'review'     => [
                'id'                  => $review->id,
                'employee_name'       => $this->personName($review->employee),
                'manager_name'        => $this->personName($review->manager),
                'cycle_name'          => $review->cycle?->name,
                'probation_type'      => $review->probation_type,
                'start_date'          => $review->start_date->format('Y-m-d'),
                'end_date'            => $review->end_date->format('Y-m-d'),
                'status'              => $review->status,
                'recommendation'      => $review->recommendation,
                'risk_score'          => $review->risk_score,
                'risk_level'          => $review->risk_level,
                'success_probability' => $review->success_probability,
                'ai_prediction'       => $review->ai_prediction,
                'ai_summary'          => $review->ai_summary,
                'notes'               => $review->notes,
                'days_remaining'      => $review->daysRemaining(),
                'percent_elapsed'     => $review->percentElapsed(),
                'objectives_completion_rate' => $review->objectivesCompletionRate(),
            ],
            'objectives' => $review->objectives->map(fn($o) => [
                'id'              => $o->id,
                'title'           => $o->title,
                'description'     => $o->description,
                'weight'          => $o->weight,
                'target_value'    => $o->target_value,
                'achieved_value'  => $o->achieved_value,
                'unit'            => $o->unit,
                'status'          => $o->status,
                'manager_notes'   => $o->manager_notes,
                'achievement_pct' => $o->achievementPct(),
            ]),
            'checkins'   => $review->checkins->map(fn($c) => [
                'id'            => $c->id,
                'checkin_type'  => $c->checkin_type,
                'scheduled_at'  => $c->scheduled_at->format('d M Y H:i'),
                'completed_at'  => $c->completed_at?->format('d M Y H:i'),
                'agenda'        => $c->agenda,
                'notes'         => $c->notes,
                'action_items'  => $c->action_items ?? [],
                'status'        => $c->status,
            ]),
            'feedback'   => $review->feedback->where('is_draft', false)->values()->map(fn($f) => [
                'id'            => $f->id,
                'feedback_type' => $f->feedback_type,
                'content'       => $f->content,
                'rating'        => $f->rating,
                'author_name'   => $f->author?->email ?? '-',
                'created_at'    => $f->created_at->format('d M Y'),
            ]),
            'risk_history' => $review->riskScores->sortByDesc('created_at')->values()->map(fn($rs) => [
                'id'                  => $rs->id,
                'risk_score'          => $rs->risk_score,
                'risk_level'          => $rs->risk_level,
                'success_probability' => $rs->success_probability,
                'recommendation'      => $rs->recommendation,
                'factors'             => $rs->factors,
                'is_ai_generated'     => $rs->is_ai_generated,
                'created_at'          => $rs->created_at->format('d M Y H:i'),
            ]),
            'employees'  => $this->personList(),
        ]);
    }

    // ── Objectives ────────────────────────────────────────────────────────────

    public function objectiveStore(Request $request, PaProbationReview $review): RedirectResponse
    {
        $data = $request->validate([
            'title'          => ['required', 'string', 'max:300'],
            'description'    => ['nullable', 'string'],
            'weight'         => ['integer', 'min:0', 'max:100'],
            'target_value'   => ['nullable', 'numeric'],
            'unit'           => ['nullable', 'string', 'max:50'],
        ]);

        $used = $review->objectives()->sum('weight');
        $adding = $data['weight'] ?? 0;
        if ($used + $adding > 100) {
            return back()->withErrors(['weight' => "Total weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
        }

        $review->objectives()->create($data);

        return back()->with('success', 'Objective added.');
    }

    public function objectiveUpdate(Request $request, PaProbationReview $review, PaProbationObjective $objective): RedirectResponse
    {
        $data = $request->validate([
            'title'          => ['sometimes', 'string', 'max:300'],
            'description'    => ['nullable', 'string'],
            'weight'         => ['integer', 'min:0', 'max:100'],
            'target_value'   => ['nullable', 'numeric'],
            'achieved_value' => ['nullable', 'numeric'],
            'unit'           => ['nullable', 'string', 'max:50'],
            'status'         => ['sometimes', 'in:pending,in_progress,met,not_met'],
            'manager_notes'  => ['nullable', 'string'],
        ]);

        if (isset($data['weight'])) {
            $used = $review->objectives()->where('id', '!=', $objective->id)->sum('weight');
            if ($used + $data['weight'] > 100) {
                return back()->withErrors(['weight' => "Total weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $objective->update($data);

        return back()->with('success', 'Objective updated.');
    }

    public function objectiveDestroy(PaProbationReview $review, PaProbationObjective $objective): RedirectResponse
    {
        $objective->delete();

        return back()->with('success', 'Objective deleted.');
    }

    // ── Check-ins ─────────────────────────────────────────────────────────────

    public function checkinStore(Request $request, PaProbationReview $review): RedirectResponse
    {
        $data = $request->validate([
            'checkin_type'  => ['required', 'in:formal,informal'],
            'scheduled_at'  => ['required', 'date'],
            'agenda'        => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;

        $checkin = $review->checkins()->create($data);

        // Notify the probation employee
        if ($review->employee_id && $review->employee_id !== $request->user()->id) {
            $employee = User::find($review->employee_id);
            if ($employee) {
                $this->safeNotify($employee, new PaProbationCheckinNotification($checkin, 'scheduled'));
            }
        }

        return back()->with('success', 'Check-in scheduled.');
    }

    public function checkinUpdate(Request $request, PaProbationReview $review, PaProbationCheckin $checkin): RedirectResponse
    {
        $data = $request->validate([
            'checkin_type'  => ['sometimes', 'in:formal,informal'],
            'scheduled_at'  => ['sometimes', 'date'],
            'completed_at'  => ['nullable', 'date'],
            'agenda'        => ['nullable', 'string'],
            'notes'         => ['nullable', 'string'],
            'action_items'  => ['nullable', 'array'],
            'status'        => ['sometimes', 'in:scheduled,completed,missed'],
        ]);

        $checkin->update($data);

        return back()->with('success', 'Check-in updated.');
    }

    public function checkinDestroy(PaProbationReview $review, PaProbationCheckin $checkin): RedirectResponse
    {
        $checkin->delete();

        return back()->with('success', 'Check-in deleted.');
    }

    // ── Feedback ──────────────────────────────────────────────────────────────

    public function feedbackStore(Request $request, PaProbationReview $review): RedirectResponse
    {
        $data = $request->validate([
            'feedback_type' => ['required', 'in:manager,self,peer'],
            'content'       => ['required', 'string', 'min:20'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:5'],
            'is_draft'      => ['boolean'],
        ]);
        $data['given_by'] = $request->user()->id;

        $feedback = $review->feedback()->create($data);

        // Notify employee — skip self-feedback and drafts
        $isSelf = $review->employee_id === $request->user()->id;
        if (!$isSelf && !($data['is_draft'] ?? false) && $review->employee_id) {
            $employee = User::find($review->employee_id);
            if ($employee) {
                $feedback->load('author.person');
                $this->safeNotify($employee, new PaProbationFeedbackNotification($feedback));
            }
        }

        return back()->with('success', 'Feedback submitted.');
    }

    public function feedbackDestroy(PaProbationReview $review, PaProbationFeedback $feedback): RedirectResponse
    {
        $feedback->delete();

        return back()->with('success', 'Feedback deleted.');
    }

    // ── Risk & AI ─────────────────────────────────────────────────────────────

    public function calculateRisk(Request $request, PaProbationReview $review): RedirectResponse
    {
        $this->riskService->calculateRisk($review, $request->user()->id);

        return back()->with('success', 'Risk score calculated.');
    }

    public function generateAiPrediction(Request $request, PaProbationReview $review): RedirectResponse
    {
        $this->riskService->generateAiPrediction($review, $request->user()->id);

        return back()->with('success', 'AI prediction generated.');
    }

    public function finalise(Request $request, PaProbationReview $review): RedirectResponse
    {
        $data = $request->validate([
            'status'         => ['required', 'in:confirmed,extended,terminated'],
            'recommendation' => ['required', 'in:confirm,extend,terminate'],
            'notes'          => ['nullable', 'string'],
        ]);

        $review->update($data);
        $this->policyGuard->forProbationFinalised($review, $data['status'], $request->user()->id);

        return back()->with('success', "Probation {$data['status']}.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**

     * Everybody this tenant may choose between.

     *

     * Was built here, in two different shapes across these controllers — some through

     * `User`, which leans on a global scope that steps aside for a platform administrator,

     * and some as a raw `DB::table('users')` where no scope applied at all and `users`

     * carries no row-level security to catch it. One of them offered every tenant's people

     * to every other tenant; the ones reading `hr_people` offered almost nobody.

     */

    private function personList(): \Illuminate\Support\Collection

    {

        return collect($this->tenantPeopleOptions());

    }

    private function personName(?User $user): string
    {
        if (!$user) return '-';
        $first   = $user->person?->first_name ?? '';
        $surname = $user->person?->surname      ?? '';
        $full    = trim("$first $surname");
        return $full ?: $user->email;
    }
}
