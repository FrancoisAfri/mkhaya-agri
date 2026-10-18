<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCategory;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsCourseContent;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLearningPath;
use App\Modules\HR\LMS\Models\LmsLearningPathCourse;
use App\Modules\HR\LMS\Models\LmsQuizQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsAdminController extends Controller
{
    public function dashboard(): Response
    {
        $totalCourses      = LmsCourse::count();
        $totalEnrollments  = LmsEnrollment::count();
        $completedCount    = LmsEnrollment::where('status', 'completed')->count();
        $completionRate    = $totalEnrollments > 0
            ? round(($completedCount / $totalEnrollments) * 100, 1)
            : 0;

        $mandatoryEnrollments = LmsEnrollment::whereHas('course', fn ($q) => $q->where('is_mandatory', true))->count();
        $mandatoryCompleted   = LmsEnrollment::whereHas('course', fn ($q) => $q->where('is_mandatory', true))
            ->where('status', 'completed')
            ->count();
        $mandatoryCompliance  = $mandatoryEnrollments > 0
            ? round(($mandatoryCompleted / $mandatoryEnrollments) * 100, 1)
            : 0;

        $popularCourses = LmsCourse::withCount('enrollments')
            ->orderByDesc('enrollments_count')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id'               => $c->id,
                'uuid'             => $c->uuid,
                'title'            => $c->title,
                'type'             => $c->type,
                'is_mandatory'     => $c->is_mandatory,
                'enrollments_count'=> $c->enrollments_count,
                'completion_rate'  => $c->completionRate(),
            ]);

        $overdueEnrollments = LmsEnrollment::with(['user.person', 'course'])
            ->whereHas('course', fn ($q) => $q->where('is_mandatory', true))
            ->whereNotIn('status', ['completed'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->limit(20)
            ->get()
            ->map(fn ($e) => [
                'id'       => $e->id,
                'user'     => ['id' => $e->user->id, 'name' => $e->user->name],
                'course'   => ['id' => $e->course->id, 'title' => $e->course->title],
                'status'   => $e->status,
                'due_date' => $e->due_date?->toDateString(),
            ]);

        return Inertia::render('Modules/LMS/Admin/Dashboard', [
            'stats' => [
                'total_courses'       => $totalCourses,
                'total_enrollments'   => $totalEnrollments,
                'completion_rate'     => $completionRate,
                'mandatory_compliance'=> $mandatoryCompliance,
            ],
            'popularCourses'     => $popularCourses,
            'overdueEnrollments' => $overdueEnrollments,
        ]);
    }

    public function courses(Request $request): Response
    {
        $query = LmsCourse::with('category')
            ->withCount('enrollments')
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->when($request->filled('published'), function ($q) use ($request) {
                if ($request->published === 'true') {
                    return $q->where('is_published', true);
                }
                return $q->where('is_published', false);
            })
            ->when($request->filled('mandatory'), function ($q) use ($request) {
                if ($request->mandatory === 'true') {
                    return $q->where('is_mandatory', true);
                }
                return $q->where('is_mandatory', false);
            })
            ->latest();

        $courses = $query->paginate(25)->withQueryString();

        $categories = LmsCategory::orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('Modules/LMS/Admin/Courses', [
            'courses'    => $courses->through(fn ($c) => [
                'id'               => $c->id,
                'uuid'             => $c->uuid,
                'title'            => $c->title,
                'description'      => $c->description,
                'type'             => $c->type,
                'duration_mins'    => $c->duration_mins,
                'pass_score'       => $c->pass_score,
                'is_mandatory'     => $c->is_mandatory,
                'is_published'                  => $c->is_published,
                'is_qcto_registered'            => $c->is_qcto_registered ?? false,
                'seta_accreditation_number'     => $c->seta_accreditation_number,
                'seta_accreditation_expires_at' => $c->seta_accreditation_expires_at?->toDateString(),
                'enrollments_count'             => $c->enrollments_count,
                'completion_rate'               => $c->completionRate(),
                'category'                      => $c->category ? [
                    'id'    => $c->category->id,
                    'name'  => $c->category->name,
                    'color' => $c->category->color,
                ] : null,
            ]),
            'categories' => $categories,
            'filters'    => $request->only('search', 'category', 'published', 'mandatory'),
        ]);
    }

public function storeCourse(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'title'        => ['required', 'string', 'max:255'],
        'description'  => ['nullable', 'string'],
        'category_id'  => ['nullable', 'integer', 'exists:lms_categories,id'],
        'cover_image'  => ['nullable', 'string', 'max:255'],
        'type'         => ['required', 'in:video,document,quiz,scorm,external'],
        'duration_mins'=> ['nullable', 'integer', 'min:0'],
        'pass_score'   => ['nullable', 'integer', 'min:0', 'max:100'],
        'is_mandatory' => ['boolean'],
        'is_published' => ['boolean'],
    ]);

    LmsCourse::create(array_merge($validated, [
        'pass_score'   => $validated['pass_score'] ?? 0,
        'created_by'   => Auth::id(),
        'is_mandatory' => $request->boolean('is_mandatory'),
        'is_published' => $request->boolean('is_published'),
    ]));

    return redirect()->route('lms.admin.courses')->with('success', 'Course created successfully.');
}

    public function updateCourse(Request $request, LmsCourse $course): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'category_id'  => ['nullable', 'integer', 'exists:lms_categories,id'],
            'cover_image'  => ['nullable', 'string', 'max:255'],
            'type'         => ['required', 'in:video,document,quiz,scorm,external'],
            'duration_mins'=> ['nullable', 'integer', 'min:0'],
            'pass_score'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_mandatory' => ['boolean'],
            'is_published' => ['boolean'],
        ]);

        $course->update(array_merge($validated, [
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_published' => $request->boolean('is_published'),
        ]));

        return redirect()->route('lms.admin.courses')->with('success', 'Course updated successfully.');
    }

    public function destroyCourse(LmsCourse $course): RedirectResponse
    {
        $course->delete();

        return redirect()->route('lms.admin.courses')->with('success', 'Course deleted.');
    }

    public function publishCourse(LmsCourse $course): RedirectResponse
    {
        $course->update(['is_published' => !$course->is_published]);

        return redirect()->back()->with('success', $course->is_published ? 'Course published.' : 'Course unpublished.');
    }

    public function storeContent(Request $request, LmsCourse $course): RedirectResponse
    {
        $validated = $request->validate([
            'type'         => ['required', 'in:video,document,text,link,quiz'],
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['nullable', 'string'],
            'url'          => ['nullable', 'string', 'max:500'],
            'file_path'    => ['nullable', 'string', 'max:500'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
            'duration_mins'=> ['nullable', 'integer', 'min:0'],
        ]);

        $course->contents()->create($validated);

        return redirect()->back()->with('success', 'Content added.');
    }

    public function destroyContent(LmsCourse $course, LmsCourseContent $content): RedirectResponse
    {
        abort_unless($content->course_id === $course->id, 404);
        $content->delete();

        return redirect()->back()->with('success', 'Content removed.');
    }

    public function storeQuestion(Request $request, LmsCourse $course): RedirectResponse
    {
        $validated = $request->validate([
            'question'   => ['required', 'string'],
            'type'       => ['required', 'in:mcq,true_false,multi_select'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'options'    => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string', 'max:255'],
            'options.*.is_correct'  => ['required', 'boolean'],
            'options.*.sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $question = $course->quizQuestions()->create([
            'question'   => $validated['question'],
            'type'       => $validated['type'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        foreach ($validated['options'] as $index => $option) {
            $question->options()->create([
                'option_text' => $option['option_text'],
                'is_correct'  => (bool) ($option['is_correct'] ?? false),
                'sort_order'  => $option['sort_order'] ?? $index,
            ]);
        }

        return redirect()->back()->with('success', 'Question added.');
    }

    public function destroyQuestion(LmsCourse $course, LmsQuizQuestion $question): RedirectResponse
    {
        abort_unless($question->course_id === $course->id, 404);
        $question->delete();

        return redirect()->back()->with('success', 'Question removed.');
    }

    public function enrollments(Request $request): Response
    {
        $query = LmsEnrollment::with(['user.person', 'course'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('course'), fn ($q) => $q->where('course_id', $request->course))
            ->latest('enrolled_at');

        $enrollments = $query->paginate(25)->withQueryString();

        $courses = LmsCourse::orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/Admin/Enrollments', [
            'enrollments' => $enrollments->through(fn ($e) => [
                'id'           => $e->id,
                'status'       => $e->status,
                'progress_pct' => $e->progress_pct,
                'score'        => $e->score,
                'enrolled_at'  => $e->enrolled_at?->toDateTimeString(),
                'completed_at' => $e->completed_at?->toDateTimeString(),
                'due_date'     => $e->due_date?->toDateString(),
                'user'         => ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email],
                'course'       => ['id' => $e->course->id, 'title' => $e->course->title],
            ]),
            'courses' => $courses,
            'filters' => $request->only('status', 'course'),
        ]);
    }

    public function categories(): Response
    {
        $categories = LmsCategory::withCount('courses')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'description'  => $c->description,
                'icon'         => $c->icon,
                'color'        => $c->color,
                'sort_order'   => $c->sort_order,
                'courses_count'=> $c->courses_count,
            ]);

        return Inertia::render('Modules/LMS/Admin/Categories', [
            'categories' => $categories,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        LmsCategory::create($validated);

        return redirect()->route('lms.admin.categories')->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, LmsCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $category->update($validated);

        return redirect()->route('lms.admin.categories')->with('success', 'Category updated.');
    }

    public function destroyCategory(LmsCategory $category): RedirectResponse
    {
        $category->delete();

        return redirect()->route('lms.admin.categories')->with('success', 'Category deleted.');
    }

    // ── Learning Paths ──────────────────────────────────────────────────────────

    public function learningPaths(): Response
    {
        $paths = LmsLearningPath::withCount('pathCourses')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id'                 => $p->id,
                'uuid'               => $p->uuid,
                'title'              => $p->title,
                'description'        => $p->description,
                'is_mandatory'       => $p->is_mandatory,
                'is_published'       => $p->is_published,
                'path_courses_count' => $p->path_courses_count,
            ]);

        $courses = LmsCourse::where('is_published', true)
            ->orderBy('title')
            ->get(['id', 'title', 'type', 'duration_mins', 'is_mandatory']);

        return Inertia::render('Modules/LMS/Admin/LearningPaths', [
            'paths'   => $paths,
            'courses' => $courses,
        ]);
    }

    public function storeLearningPath(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'is_mandatory' => ['boolean'],
            'is_published' => ['boolean'],
        ]);

        LmsLearningPath::create(array_merge($validated, [
            'created_by'   => Auth::id(),
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_published' => $request->boolean('is_published'),
        ]));

        return redirect()->route('lms.admin.learning-paths')->with('success', 'Learning path created.');
    }

    public function updateLearningPath(Request $request, LmsLearningPath $path): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'is_mandatory' => ['boolean'],
            'is_published' => ['boolean'],
        ]);

        $path->update(array_merge($validated, [
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_published' => $request->boolean('is_published'),
        ]));

        return redirect()->route('lms.admin.learning-paths')->with('success', 'Learning path updated.');
    }

    public function destroyLearningPath(LmsLearningPath $path): RedirectResponse
    {
        $path->delete();

        return redirect()->route('lms.admin.learning-paths')->with('success', 'Learning path deleted.');
    }

    public function publishLearningPath(LmsLearningPath $path): RedirectResponse
    {
        $path->update(['is_published' => !$path->is_published]);

        return redirect()->back()->with('success', $path->is_published ? 'Path published.' : 'Path unpublished.');
    }

    public function addCourseToPath(Request $request, LmsLearningPath $path): RedirectResponse
    {
        $request->validate([
            'course_id'  => ['required', 'integer', 'exists:lms_courses,id'],
            'is_required'=> ['boolean'],
        ]);

        LmsLearningPathCourse::firstOrCreate(
            ['path_id' => $path->id, 'course_id' => $request->course_id],
            [
                'sort_order'  => $path->pathCourses()->max('sort_order') + 1,
                'is_required' => $request->boolean('is_required', true),
            ]
        );

        return redirect()->back()->with('success', 'Course added to path.');
    }

    public function removeCourseFromPath(LmsLearningPath $path, LmsCourse $course): RedirectResponse
    {
        LmsLearningPathCourse::where('path_id', $path->id)
            ->where('course_id', $course->id)
            ->delete();

        return redirect()->back()->with('success', 'Course removed from path.');
    }
}
