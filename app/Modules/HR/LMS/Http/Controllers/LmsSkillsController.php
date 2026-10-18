<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsCourseSkill;
use App\Modules\HR\LMS\Models\LmsEmployeeSkill;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsJobTitleSkill;
use App\Modules\HR\LMS\Models\LmsQuestion;
use App\Modules\HR\LMS\Models\LmsQuestionSkillMapping;
use App\Modules\HR\LMS\Models\LmsSkill;
use App\Modules\HR\LMS\Services\LmsSkillsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LmsSkillsController
{
    public function __construct(private LmsSkillsService $service) {}

    // ── Library ─────────────────────────────────────────────────────────────

    public function libraryIndex(Request $request): Response
    {
        $skills = LmsSkill::withCount(['jobTitleMappings', 'courseMappings', 'questionMappings', 'employeeSkills'])
            ->withTrashed(false)
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%' . $request->input('search') . '%'))
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(40)
            ->withQueryString();

        $positions = HrPosition::orderBy('name')->get(['id', 'name']);
        $courses   = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);
        $questions = LmsQuestion::orderBy('question_text')->get(['id', 'question_text', 'type']);

        return Inertia::render('Modules/LMS/Admin/SkillsLibrary', [
            'skills'     => $skills,
            'positions'  => $positions,
            'courses'    => $courses,
            'questions'  => $questions,
            'categories' => LmsSkill::CATEGORIES,
            'filters'    => $request->only(['category', 'search']),
        ]);
    }

    public function storeSkill(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'description'  => 'nullable|string',
            'category'     => 'nullable|string',
            'max_level'    => 'integer|min:2|max:10',
            'level_labels' => 'nullable|array',
            'is_active'    => 'boolean',
        ]);

        $this->service->createSkill($data, $request->user()->id);
        return back()->with('success', 'Skill created.');
    }

    public function updateSkill(Request $request, LmsSkill $skill): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'description'  => 'nullable|string',
            'category'     => 'nullable|string',
            'max_level'    => 'integer|min:2|max:10',
            'level_labels' => 'nullable|array',
            'is_active'    => 'boolean',
        ]);

        $skill->update($data);
        return back()->with('success', 'Skill updated.');
    }

    public function destroySkill(LmsSkill $skill): \Illuminate\Http\RedirectResponse
    {
        $skill->delete();
        return back()->with('success', 'Skill removed.');
    }

    // ── Job title mappings ───────────────────────────────────────────────────

    public function mapJobTitle(Request $request, LmsSkill $skill): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'position_id'    => 'required|exists:hr_positions,id',
            'required_level' => 'required|integer|min:1|max:10',
            'is_mandatory'   => 'boolean',
        ]);

        $this->service->mapSkillToJobTitle($skill->id, $data['position_id'], $data['required_level'], $data['is_mandatory'] ?? true);
        return back()->with('success', 'Skill mapped to job title.');
    }

    public function unmapJobTitle(LmsSkill $skill, int $positionId): \Illuminate\Http\RedirectResponse
    {
        $this->service->unmapSkillFromJobTitle($skill->id, $positionId);
        return back()->with('success', 'Mapping removed.');
    }

    // ── Course mappings ──────────────────────────────────────────────────────

    public function mapCourse(Request $request, LmsSkill $skill): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'course_id'          => 'required|exists:lms_courses,id',
            'skill_level_gained' => 'required|integer|min:1|max:10',
        ]);

        $this->service->mapSkillToCourse($skill->id, $data['course_id'], $data['skill_level_gained']);
        return back()->with('success', 'Skill mapped to course.');
    }

    public function unmapCourse(LmsSkill $skill, int $courseId): \Illuminate\Http\RedirectResponse
    {
        $this->service->unmapSkillFromCourse($skill->id, $courseId);
        return back()->with('success', 'Mapping removed.');
    }

    // ── Question mappings ────────────────────────────────────────────────────

    public function mapQuestion(Request $request, LmsSkill $skill): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['question_id' => 'required|exists:lms_questions,id']);
        $this->service->mapQuestionToSkill($request->integer('question_id'), $skill->id);
        return back()->with('success', 'Question mapped.');
    }

    public function unmapQuestion(LmsSkill $skill, int $questionId): \Illuminate\Http\RedirectResponse
    {
        $this->service->unmapQuestionFromSkill($questionId, $skill->id);
        return back()->with('success', 'Mapping removed.');
    }

    // ── Employee skill management ────────────────────────────────────────────

    public function updateEmployeeSkill(Request $request, int $userId, int $skillId): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'current_level' => 'required|integer|min:0|max:10',
            'verified'      => 'boolean',
            'notes'         => 'nullable|string',
        ]);

        LmsEmployeeSkill::updateOrCreate(
            ['user_id' => $userId, 'skill_id' => $skillId],
            [
                'current_level'    => $data['current_level'],
                'verified'         => $data['verified'] ?? false,
                'verified_by'      => ($data['verified'] ?? false) ? $request->user()->id : null,
                'verified_at'      => ($data['verified'] ?? false) ? now() : null,
                'source'           => 'manager',
                'notes'            => $data['notes'] ?? null,
                'last_assessed_at' => now(),
            ]
        );

        return back()->with('success', 'Skill level updated.');
    }

    public function calculateGaps(Request $request, int $userId): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['position_id' => 'required|exists:hr_positions,id']);
        $this->service->calculateSkillGap($userId, $request->integer('position_id'));
        return back()->with('success', 'Skill gaps calculated.');
    }

    public function syncSkillsAfterEnrollment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'user_id'   => 'required|exists:users,id',
            'course_id' => 'required|exists:lms_courses,id',
        ]);

        $this->service->updateEmployeeSkillAfterPass(
            $request->integer('user_id'),
            $request->integer('course_id')
        );

        return back()->with('success', 'Skills synced from completed training.');
    }

    // ── Skills matrix ────────────────────────────────────────────────────────

    public function matrixIndex(Request $request): Response
    {
        $filters = $request->only(['position_id', 'category', 'skill_ids']);

        $matrix    = $this->service->generateSkillsMatrix($filters);
        $positions = HrPosition::orderBy('name')->get(['id', 'name']);
        $categories= LmsSkill::CATEGORIES;

        // Skills for filter dropdown
        $allSkills = LmsSkill::active()->orderBy('category')->orderBy('name')->get(['id', 'name', 'category']);

        return Inertia::render('Modules/LMS/Admin/SkillsMatrix', [
            'matrix'     => $matrix,
            'positions'  => $positions,
            'allSkills'  => $allSkills,
            'categories' => $categories,
            'filters'    => $filters,
        ]);
    }

    // ── Succession readiness ─────────────────────────────────────────────────

    public function successionIndex(Request $request): Response
    {
        $positionId = $request->integer('position_id', 0) ?: null;
        $succession = $positionId ? $this->service->generateSuccessionReadiness($positionId) : null;

        $positions  = HrPosition::orderBy('name')->get(['id', 'name']);

        // Open gap summary for dashboard widget
        $openGapsSummary = LmsEmployeeSkillGap::where('status', 'open')
            ->selectRaw('position_id, count(*) as gap_count, count(distinct user_id) as employee_count')
            ->groupBy('position_id')
            ->with('position:id,name')
            ->get()
            ->map(fn ($g) => [
                'position_id'     => $g->position_id,
                'position_name'   => $g->position?->name ?? '—',
                'gap_count'       => $g->gap_count,
                'employee_count'  => $g->employee_count,
            ]);

        return Inertia::render('Modules/LMS/Admin/SuccessionReadiness', [
            'succession'      => $succession,
            'positions'       => $positions,
            'openGapsSummary' => $openGapsSummary,
            'selectedPosition'=> $positionId,
        ]);
    }
}
