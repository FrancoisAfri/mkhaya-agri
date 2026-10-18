<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentActivity;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaLearningRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Bridges Performance development plans with the LMS: turns a development
 * activity or AI learning recommendation into a real LMS course enrolment,
 * and exposes the published course catalogue for picking.
 */
class PaDevLmsController extends Controller
{
    /** Searchable list of published LMS courses for the course-picker. */
    public function courses(Request $request): JsonResponse
    {
        $term = $request->query('q');

        $courses = LmsCourse::query()
            ->where('is_published', true)
            ->when($term, fn ($q) => $q->where('title', 'ilike', "%{$term}%"))
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'duration_mins', 'nqf_level', 'credits'])
            ->map(fn ($c) => [
                'id'       => $c->id,
                'title'    => $c->title,
                'duration' => $c->duration_mins,
                'nqf'      => $c->nqf_level,
                'credits'  => $c->credits,
            ]);

        return response()->json(['courses' => $courses]);
    }

    /** Link a development activity to an LMS course and enrol the plan owner. */
    public function linkActivity(Request $request, PaDevelopmentActivity $activity): RedirectResponse
    {
        $data = $request->validate(['lms_course_id' => 'required|exists:lms_courses,id']);

        $plan = PaDevelopmentPlan::find($activity->plan_id);
        $enrollment = $this->enrol((int) $plan->employee_id, (int) $data['lms_course_id']);

        $activity->update([
            'lms_course_id'     => $data['lms_course_id'],
            'lms_enrollment_id' => $enrollment->id,
            'provider'          => $activity->provider ?: 'Internal LMS',
        ]);

        return back()->with('success', 'Activity linked to LMS course and learner enrolled.');
    }

    /** Convert an AI learning recommendation into an enrolment + development activity. */
    public function enrolRecommendation(Request $request, PaLearningRecommendation $recommendation): RedirectResponse
    {
        $data = $request->validate([
            'lms_course_id' => 'required|exists:lms_courses,id',
            'plan_id'       => 'nullable|exists:pa_development_plans,id',
        ]);

        $enrollment = $this->enrol((int) $recommendation->employee_id, (int) $data['lms_course_id']);

        $recommendation->update([
            'lms_course_id'     => $data['lms_course_id'],
            'lms_enrollment_id' => $enrollment->id,
            'status'            => 'enrolled',
        ]);

        // Mirror it as a tracked development activity if a plan is supplied.
        $planId = $data['plan_id'] ?? $recommendation->development_plan_id;
        if ($planId) {
            PaDevelopmentActivity::create([
                'plan_id'           => $planId,
                'title'             => $recommendation->title,
                'activity_type'     => 'course',
                'description'       => $recommendation->description,
                'status'            => 'in_progress',
                'provider'          => $recommendation->provider ?: 'Internal LMS',
                'lms_course_id'     => $data['lms_course_id'],
                'lms_enrollment_id' => $enrollment->id,
            ]);
        }

        return back()->with('success', 'Enrolled in LMS course from recommendation.');
    }

    private function enrol(int $userId, int $courseId): LmsEnrollment
    {
        return LmsEnrollment::firstOrCreate(
            ['user_id' => $userId, 'course_id' => $courseId],
            [
                'uuid'        => (string) Str::uuid(),
                'status'      => 'enrolled',
                'progress_pct'=> 0,
                'enrolled_at' => now(),
            ]
        );
    }
}
