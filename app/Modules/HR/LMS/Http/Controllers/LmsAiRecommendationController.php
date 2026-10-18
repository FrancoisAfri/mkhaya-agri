<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsAiRecommendation;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLearningPlan;
use App\Modules\HR\LMS\Models\LmsRevisionPlan;
use App\Modules\HR\LMS\Services\LmsAiRecommendationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LmsAiRecommendationController
{
    public function __construct(private LmsAiRecommendationService $service) {}

    // ── Admin ───────────────────────────────────────────────────────────────

    public function adminIndex(Request $request): Response
    {
        $query = LmsAiRecommendation::with(['user.person', 'course'])
            ->orderByDesc('generated_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('type')) {
            $query->where('recommendation_type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $recommendations = $query->paginate(25)->withQueryString();

        $employees = User::with('person')
            ->whereHas('person')
            ->orderBy('id')
            ->get()
            ->map(fn ($u) => [
                'id'   => $u->id,
                'name' => $u->person ? trim("{$u->person->first_name} {$u->person->surname}") : $u->email,
            ]);

        $plans = LmsLearningPlan::with('user.person')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id'                     => $p->id,
                'title'                  => $p->title,
                'plan_type'              => $p->plan_type,
                'status'                 => $p->status,
                'ai_generated'           => $p->ai_generated,
                'target_completion_date' => $p->target_completion_date?->toDateString(),
                'learner_name'           => $p->user?->person
                    ? trim("{$p->user->person->first_name} {$p->user->person->surname}")
                    : $p->user?->email,
                'items_count'            => $p->items()->count(),
                'completion_pct'         => $p->completionPct(),
            ]);

        return Inertia::render('Modules/LMS/Admin/AiRecommendations', [
            'recommendations' => $recommendations,
            'employees'       => $employees,
            'plans'           => $plans,
            'types'           => LmsAiRecommendation::TYPES,
            'filters'         => $request->only(['user_id', 'type', 'status']),
        ]);
    }

    public function generateForEmployee(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'type'    => 'required|in:all,job_title,performance_gap,failed_topics,learning_plan,revision_plan',
        ]);

        $user      = User::with('person.hrPosition')->findOrFail($request->integer('user_id'));
        $adminId   = $request->user()->id;

        match ($request->input('type')) {
            'job_title'       => $this->service->recommendCoursesByJobTitle($user, $adminId),
            'performance_gap' => $this->service->recommendCoursesByPerformanceGap($user, $adminId),
            'failed_topics'   => $this->service->recommendCoursesByFailedTopics($user, $adminId),
            'learning_plan'   => $this->service->generateLearningPlan($user, $adminId),
            default           => $this->service->recommendCoursesForEmployee($user, $adminId),
        };

        return back()->with('success', 'AI analysis complete.');
    }

    public function updateStatus(Request $request, LmsAiRecommendation $recommendation): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['status' => 'required|in:pending,accepted,dismissed']);
        $recommendation->update(['status' => $request->input('status')]);
        return back()->with('success', 'Status updated.');
    }

    public function destroyRecommendation(LmsAiRecommendation $recommendation): \Illuminate\Http\RedirectResponse
    {
        $recommendation->delete();
        return back()->with('success', 'Recommendation removed.');
    }

    // ── Learner ─────────────────────────────────────────────────────────────

    public function myLearningPlan(Request $request): Response
    {
        $userId = $request->user()->id;

        $plan = LmsLearningPlan::with(['items.course'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->latest()
            ->first();

        $revisionPlans = LmsRevisionPlan::with('course')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('generated_at')
            ->get()
            ->map(fn ($r) => [
                'id'              => $r->id,
                'course_title'    => $r->course?->title,
                'estimated_hours' => $r->estimated_hours,
                'topic_areas'     => $r->topic_areas ?? [],
                'ai_suggestions'  => $r->ai_suggestions ?? [],
                'status'          => $r->status,
                'generated_at'    => $r->generated_at?->toDateString(),
            ]);

        $recommendations = LmsAiRecommendation::with('course')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('priority')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'                  => $r->id,
                'course_title'        => $r->course?->title,
                'recommendation_type' => $r->recommendation_type,
                'type_label'          => LmsAiRecommendation::TYPES[$r->recommendation_type] ?? $r->recommendation_type,
                'reason'              => $r->reason,
                'priority'            => $r->priority,
                'course_id'           => $r->course_id,
            ]);

        $planData = null;
        if ($plan) {
            $planData = [
                'id'                     => $plan->id,
                'title'                  => $plan->title,
                'description'            => $plan->description,
                'plan_type'              => $plan->plan_type,
                'status'                 => $plan->status,
                'target_completion_date' => $plan->target_completion_date?->toDateString(),
                'completion_pct'         => $plan->completionPct(),
                'items'                  => $plan->items->map(fn ($i) => [
                    'id'          => $i->id,
                    'course_id'   => $i->course_id,
                    'course_title'=> $i->course?->title,
                    'item_type'   => $i->item_type,
                    'title'       => $i->title,
                    'description' => $i->description,
                    'reason'      => $i->reason,
                    'priority'    => $i->priority,
                    'due_date'    => $i->due_date?->toDateString(),
                    'status'      => $i->status,
                ])->all(),
            ];
        }

        return Inertia::render('Modules/LMS/LearningPlan', [
            'plan'            => $planData,
            'revisionPlans'   => $revisionPlans,
            'recommendations' => $recommendations,
        ]);
    }

    public function updateItemStatus(Request $request, int $itemId): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['status' => 'required|in:pending,in_progress,completed,skipped']);

        $item = \App\Modules\HR\LMS\Models\LmsLearningPlanItem::whereHas(
            'learningPlan',
            fn ($q) => $q->where('user_id', $request->user()->id)
        )->findOrFail($itemId);

        $item->update([
            'status'       => $request->input('status'),
            'completed_at' => $request->input('status') === 'completed' ? now() : null,
        ]);

        return back()->with('success', 'Progress saved.');
    }

    public function generateRevisionPlan(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['enrollment_id' => 'required|exists:lms_enrollments,id']);

        $user       = $request->user();
        $enrollment = LmsEnrollment::where('user_id', $user->id)
            ->findOrFail($request->integer('enrollment_id'));

        $this->service->generatePersonalizedRevisionPlan($user, $enrollment->id, $user->id);

        return back()->with('success', 'Revision plan generated.');
    }
}
