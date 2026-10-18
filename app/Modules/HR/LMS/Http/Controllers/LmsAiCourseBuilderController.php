<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Jobs\GenerateAiCourseJob;
use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LmsAiCourseBuilderController extends Controller
{
    /**
     * Validate the generation parameters, dispatch the background job,
     * and return immediately. The job notifies the user when complete.
     */
    public function generate(Request $request, LmsCourse $course): JsonResponse
    {
        $params = $request->validate([
            'level'              => ['required', 'in:intern,junior,intermediate,senior,executive'],
            'industry_context'   => ['nullable', 'string', 'max:200'],
            'tone'               => ['required', 'in:professional,conversational,technical'],
            'num_modules'        => ['required', 'integer', 'min:2', 'max:8'],
            'lessons_per_module' => ['required', 'integer', 'min:2', 'max:6'],
            'include_quiz'       => ['boolean'],
            'quiz_count'         => ['nullable', 'integer', 'min:3', 'max:30'],
            'case_studies'       => ['boolean'],
            'language'           => ['required', 'in:English,Afrikaans,Zulu,Xhosa,Sesotho'],
            'extra_objectives'   => ['nullable', 'string', 'max:500'],
        ]);

        GenerateAiCourseJob::dispatch($course->id, Auth::id(), $params);

        return response()->json([
            'message' => 'Course generation has started. You will receive a notification when it is ready.',
        ]);
    }
}
