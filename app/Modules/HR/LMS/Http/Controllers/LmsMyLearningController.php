<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsMyLearningController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        $allEnrollments = LmsEnrollment::with(['course.category', 'certificate'])
            ->where('user_id', $userId)
            ->latest('enrolled_at')
            ->get();

        $inProgress = $allEnrollments
            ->whereIn('status', ['enrolled', 'in_progress'])
            ->sortByDesc(fn ($e) => $e->status === 'in_progress' ? 1 : 0)
            ->values()
            ->map(fn ($e) => $this->formatEnrollment($e));

        $completed = $allEnrollments
            ->where('status', 'completed')
            ->values()
            ->map(fn ($e) => $this->formatEnrollment($e));

        $certificates = LmsCertificate::with(['enrollment.course'])
            ->whereHas('enrollment', fn ($q) => $q->where('user_id', $userId))
            ->latest('issued_at')
            ->get()
            ->map(fn ($c) => [
                'id'                 => $c->id,
                'certificate_number' => $c->certificate_number,
                'issued_at'          => $c->issued_at?->toDateTimeString(),
                'expires_at'         => $c->expires_at?->toDateTimeString(),
                'course'             => [
                    'id'    => $c->enrollment->course->id,
                    'title' => $c->enrollment->course->title,
                ],
            ]);

        $mandatoryDue = LmsCourse::where('is_mandatory', true)
            ->where('is_published', true)
            ->whereDoesntHave('enrollments', fn ($q) => $q
                ->where('user_id', $userId)
                ->whereIn('status', ['completed'])
            )
            ->with('category')
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'uuid'         => $c->uuid,
                'title'        => $c->title,
                'duration_mins'=> $c->duration_mins,
                'category'     => $c->category ? ['name' => $c->category->name, 'color' => $c->category->color] : null,
                'enrollment'   => $allEnrollments->firstWhere('course_id', $c->id)
                    ? $this->formatEnrollment($allEnrollments->firstWhere('course_id', $c->id))
                    : null,
            ]);

        return Inertia::render('Modules/LMS/MyLearning', [
            'stats' => [
                'enrolled'     => $inProgress->count(),
                'completed'    => $completed->count(),
                'certificates' => $certificates->count(),
                'mandatory_due'=> $mandatoryDue->count(),
            ],
            'inProgress'   => $inProgress,
            'completed'    => $completed,
            'certificates' => $certificates,
            'mandatoryDue' => $mandatoryDue,
        ]);
    }

    private function formatEnrollment(LmsEnrollment $enrollment): array
    {
        return [
            'id'           => $enrollment->id,
            'uuid'         => $enrollment->uuid,
            'status'       => $enrollment->status,
            'progress_pct' => $enrollment->progress_pct,
            'score'        => $enrollment->score,
            'enrolled_at'  => $enrollment->enrolled_at?->toDateTimeString(),
            'completed_at' => $enrollment->completed_at?->toDateTimeString(),
            'due_date'     => $enrollment->due_date?->toDateString(),
            'certificate'  => $enrollment->certificate ? [
                'id'                 => $enrollment->certificate->id,
                'certificate_number' => $enrollment->certificate->certificate_number,
                'issued_at'          => $enrollment->certificate->issued_at?->toDateTimeString(),
            ] : null,
            'course' => [
                'id'           => $enrollment->course->id,
                'uuid'         => $enrollment->course->uuid,
                'title'        => $enrollment->course->title,
                'type'         => $enrollment->course->type,
                'duration_mins'=> $enrollment->course->duration_mins,
                'is_mandatory' => $enrollment->course->is_mandatory,
                'category'     => $enrollment->course->category ? [
                    'name'  => $enrollment->course->category->name,
                    'color' => $enrollment->course->category->color,
                ] : null,
            ],
        ];
    }
}
