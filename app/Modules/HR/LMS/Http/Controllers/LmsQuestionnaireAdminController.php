<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsQuestion;
use App\Modules\HR\LMS\Models\LmsQuestionBank;
use App\Modules\HR\LMS\Models\LmsQuestionnaire;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsQuestionnaireAdminController extends Controller
{
    // ── Questionnaire list ───────────────────────────────────────────────────

    public function index(): Response
    {
        $questionnaires = LmsQuestionnaire::withCount('questions')
            ->with('createdBy')
            ->latest()
            ->get()
            ->map(fn ($q) => [
                'id'                  => $q->id,
                'uuid'                => $q->uuid,
                'title'               => $q->title,
                'type'                => $q->type,
                'type_label'          => LmsQuestionnaire::TYPE_LABELS[$q->type] ?? $q->type,
                'pass_score'          => $q->pass_score,
                'time_limit_mins'     => $q->time_limit_mins,
                'allow_retake'        => $q->allow_retake,
                'questions_count'     => $q->questions_count,
                'created_at'          => $q->created_at->toDateString(),
                'created_by_name'     => $q->createdBy?->person
                    ? $q->createdBy->person->first_name . ' ' . $q->createdBy->person->surname
                    : null,
            ]);

        return Inertia::render('Modules/LMS/Admin/Questionnaires', [
            'questionnaires' => $questionnaires,
            'typeLabels'     => LmsQuestionnaire::TYPE_LABELS,
        ]);
    }

    // ── Questionnaire CRUD ───────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'type'                => ['required', 'in:' . implode(',', array_keys(LmsQuestionnaire::TYPE_LABELS))],
            'description'         => ['nullable', 'string'],
            'instructions'        => ['nullable', 'string'],
            'time_limit_mins'     => ['nullable', 'integer', 'min:1'],
            'pass_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'allow_retake'        => ['boolean'],
            'max_retakes'         => ['nullable', 'integer', 'min:1'],
            'randomise_questions' => ['boolean'],
            'randomise_options'   => ['boolean'],
            'show_answers_after'  => ['boolean'],
        ]);

        $questionnaire = LmsQuestionnaire::create([
            'title'               => $request->title,
            'type'                => $request->type,
            'description'         => $request->description,
            'instructions'        => $request->instructions,
            'time_limit_mins'     => $request->time_limit_mins,
            'pass_score'          => $request->pass_score,
            'allow_retake'        => $request->boolean('allow_retake'),
            'max_retakes'         => $request->max_retakes,
            'randomise_questions' => $request->boolean('randomise_questions'),
            'randomise_options'   => $request->boolean('randomise_options'),
            'show_answers_after'  => $request->boolean('show_answers_after'),
            'created_by'          => Auth::id(),
        ]);

        return redirect()->route('lms.admin.questionnaires.builder', $questionnaire)
            ->with('success', 'Questionnaire created.');
    }

    public function update(Request $request, LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'type'                => ['required', 'in:' . implode(',', array_keys(LmsQuestionnaire::TYPE_LABELS))],
            'description'         => ['nullable', 'string'],
            'instructions'        => ['nullable', 'string'],
            'time_limit_mins'     => ['nullable', 'integer', 'min:1'],
            'pass_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'allow_retake'        => ['boolean'],
            'max_retakes'         => ['nullable', 'integer', 'min:1'],
            'randomise_questions' => ['boolean'],
            'show_answers_after'  => ['boolean'],
        ]);

        $questionnaire->update([
            'title'               => $request->title,
            'type'                => $request->type,
            'description'         => $request->description,
            'instructions'        => $request->instructions,
            'time_limit_mins'     => $request->time_limit_mins,
            'pass_score'          => $request->pass_score,
            'allow_retake'        => $request->boolean('allow_retake'),
            'max_retakes'         => $request->max_retakes,
            'randomise_questions' => $request->boolean('randomise_questions'),
            'show_answers_after'  => $request->boolean('show_answers_after'),
        ]);

        return redirect()->back()->with('success', 'Questionnaire updated.');
    }

    public function destroy(LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $questionnaire->delete();

        return redirect()->route('lms.admin.questionnaires')->with('success', 'Questionnaire deleted.');
    }

    // ── Questionnaire Builder page ───────────────────────────────────────────

    public function builder(LmsQuestionnaire $questionnaire): Response
    {
        $questionnaire->load(['questions.options', 'assignments.course', 'assignments.lesson']);

        $allCourses = LmsCourse::where('is_published', true)
            ->orderBy('title')
            ->get(['id', 'title', 'type']);

        $bankCategories = LmsQuestionBank::orderBy('title')
            ->get(['id', 'title', 'category'])
            ->groupBy('category');

        return Inertia::render('Modules/LMS/Admin/QuestionnaireBuilder', [
            'questionnaire' => [
                'id'                  => $questionnaire->id,
                'uuid'                => $questionnaire->uuid,
                'title'               => $questionnaire->title,
                'type'                => $questionnaire->type,
                'type_label'          => LmsQuestionnaire::TYPE_LABELS[$questionnaire->type] ?? $questionnaire->type,
                'description'         => $questionnaire->description,
                'instructions'        => $questionnaire->instructions,
                'time_limit_mins'     => $questionnaire->time_limit_mins,
                'pass_score'          => $questionnaire->pass_score,
                'allow_retake'        => $questionnaire->allow_retake,
                'max_retakes'         => $questionnaire->max_retakes,
                'randomise_questions' => $questionnaire->randomise_questions,
                'show_answers_after'  => $questionnaire->show_answers_after,
                'questions'           => $questionnaire->questions->map(fn ($q) => $this->formatQuestion($q)),
                'assignments'         => $questionnaire->assignments->map(fn ($a) => [
                    'id'              => $a->id,
                    'assignment_type' => $a->assignment_type,
                    'type_label'      => LmsQuestionnaireAssignment::TYPE_LABELS[$a->assignment_type] ?? $a->assignment_type,
                    'is_required'     => $a->is_required,
                    'due_days_after_enrol' => $a->due_days_after_enrol,
                    'course'          => $a->course ? ['id' => $a->course->id, 'title' => $a->course->title] : null,
                    'lesson'          => $a->lesson ? ['id' => $a->lesson->id, 'title' => $a->lesson->title] : null,
                ]),
            ],
            'allCourses'        => $allCourses,
            'bankCategories'    => $bankCategories,
            'typeLabels'        => LmsQuestionnaire::TYPE_LABELS,
            'questionTypes'     => LmsQuestion::TYPE_LABELS,
            'assignmentTypes'   => LmsQuestionnaireAssignment::TYPE_LABELS,
        ]);
    }

    // ── Question CRUD ────────────────────────────────────────────────────────

    public function storeQuestion(Request $request, LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $request->validate([
            'type'          => ['required', 'in:' . implode(',', array_keys(LmsQuestion::TYPE_LABELS))],
            'question_text' => ['required', 'string'],
            'context_text'  => ['nullable', 'string'],
            'explanation'   => ['nullable', 'string'],
            'points'        => ['required', 'integer', 'min:0'],
            'options'       => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct'  => ['boolean'],
            'options.*.rating_value'=> ['nullable', 'integer'],
            'options.*.sort_order'  => ['nullable', 'integer'],
        ]);

        $question = $questionnaire->questions()->create([
            'type'          => $request->type,
            'question_text' => $request->question_text,
            'context_text'  => $request->context_text,
            'explanation'   => $request->explanation,
            'points'        => $request->points,
            'sort_order'    => $questionnaire->questions()->max('sort_order') + 1,
        ]);

        if ($request->has('options') && in_array($request->type, LmsQuestion::HAS_OPTIONS)) {
            foreach ($request->options as $i => $opt) {
                $question->options()->create([
                    'option_text'  => $opt['option_text'],
                    'is_correct'   => $opt['is_correct'] ?? false,
                    'rating_value' => $opt['rating_value'] ?? null,
                    'sort_order'   => $opt['sort_order'] ?? $i,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Question added.');
    }

    public function updateQuestion(Request $request, LmsQuestionnaire $questionnaire, LmsQuestion $question): RedirectResponse
    {
        abort_unless($question->questionnaire_id === $questionnaire->id, 404);

        $request->validate([
            'type'          => ['required', 'in:' . implode(',', array_keys(LmsQuestion::TYPE_LABELS))],
            'question_text' => ['required', 'string'],
            'context_text'  => ['nullable', 'string'],
            'explanation'   => ['nullable', 'string'],
            'points'        => ['required', 'integer', 'min:0'],
            'options'       => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct'  => ['boolean'],
            'options.*.rating_value'=> ['nullable', 'integer'],
            'options.*.sort_order'  => ['nullable', 'integer'],
        ]);

        $question->update([
            'type'          => $request->type,
            'question_text' => $request->question_text,
            'context_text'  => $request->context_text,
            'explanation'   => $request->explanation,
            'points'        => $request->points,
        ]);

        if (in_array($request->type, LmsQuestion::HAS_OPTIONS)) {
            $question->options()->delete();
            foreach ($request->options ?? [] as $i => $opt) {
                $question->options()->create([
                    'option_text'  => $opt['option_text'],
                    'is_correct'   => $opt['is_correct'] ?? false,
                    'rating_value' => $opt['rating_value'] ?? null,
                    'sort_order'   => $opt['sort_order'] ?? $i,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Question updated.');
    }

    public function destroyQuestion(LmsQuestionnaire $questionnaire, LmsQuestion $question): RedirectResponse
    {
        abort_unless($question->questionnaire_id === $questionnaire->id, 404);
        $question->options()->delete();
        $question->delete();

        return redirect()->back()->with('success', 'Question deleted.');
    }

    public function reorderQuestions(Request $request, LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($request->order as $index => $questionId) {
            LmsQuestion::where('id', $questionId)
                ->where('questionnaire_id', $questionnaire->id)
                ->update(['sort_order' => $index]);
        }

        return redirect()->back()->with('success', 'Questions reordered.');
    }

    // ── Import from bank ─────────────────────────────────────────────────────

    public function importFromBank(Request $request, LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $request->validate([
            'question_ids'   => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:lms_questions,id'],
        ]);

        $bankQuestions = LmsQuestion::with('options')
            ->whereIn('id', $request->question_ids)
            ->whereNotNull('bank_id')
            ->get();

        foreach ($bankQuestions as $bankQuestion) {
            $bankQuestion->copyToQuestionnaire($questionnaire->id);
        }

        return redirect()->back()->with('success', count($bankQuestions) . ' question(s) imported from bank.');
    }

    // ── Assignments ──────────────────────────────────────────────────────────

    public function storeAssignment(Request $request, LmsQuestionnaire $questionnaire): RedirectResponse
    {
        $request->validate([
            'assignment_type'      => ['required', 'in:' . implode(',', array_keys(LmsQuestionnaireAssignment::TYPE_LABELS))],
            'course_id'            => ['nullable', 'integer', 'exists:lms_courses,id'],
            'lesson_id'            => ['nullable', 'integer', 'exists:lms_lessons,id'],
            'is_required'          => ['boolean'],
            'due_days_after_enrol' => ['nullable', 'integer', 'min:1'],
        ]);

        LmsQuestionnaireAssignment::create([
            'questionnaire_id'     => $questionnaire->id,
            'assignment_type'      => $request->assignment_type,
            'course_id'            => $request->course_id,
            'lesson_id'            => $request->lesson_id,
            'is_required'          => $request->boolean('is_required'),
            'due_days_after_enrol' => $request->due_days_after_enrol,
        ]);

        return redirect()->back()->with('success', 'Assignment added.');
    }

    public function destroyAssignment(LmsQuestionnaire $questionnaire, LmsQuestionnaireAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->questionnaire_id === $questionnaire->id, 404);
        $assignment->delete();

        return redirect()->back()->with('success', 'Assignment removed.');
    }

    // ── Question Banks ───────────────────────────────────────────────────────

    public function banks(): Response
    {
        $banks = LmsQuestionBank::withCount('questions')
            ->with('createdBy')
            ->orderBy('title')
            ->get()
            ->map(fn ($b) => [
                'id'              => $b->id,
                'title'           => $b->title,
                'description'     => $b->description,
                'category'        => $b->category,
                'questions_count' => $b->questions_count,
                'created_by_name' => $b->createdBy?->person
                    ? $b->createdBy->person->first_name . ' ' . $b->createdBy->person->surname
                    : null,
            ]);

        return Inertia::render('Modules/LMS/Admin/QuestionBanks', [
            'banks'         => $banks,
            'questionTypes' => LmsQuestion::TYPE_LABELS,
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category'    => ['nullable', 'string', 'max:100'],
        ]);

        LmsQuestionBank::create([
            'title'       => $request->title,
            'description' => $request->description,
            'category'    => $request->category,
            'created_by'  => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Question bank created.');
    }

    public function updateBank(Request $request, LmsQuestionBank $bank): RedirectResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category'    => ['nullable', 'string', 'max:100'],
        ]);

        $bank->update($request->only('title', 'description', 'category'));

        return redirect()->back()->with('success', 'Bank updated.');
    }

    public function destroyBank(LmsQuestionBank $bank): RedirectResponse
    {
        $bank->questions()->each(fn ($q) => $q->options()->delete());
        $bank->questions()->delete();
        $bank->delete();

        return redirect()->back()->with('success', 'Question bank deleted.');
    }

    public function storeBankQuestion(Request $request, LmsQuestionBank $bank): RedirectResponse
    {
        $request->validate([
            'type'          => ['required', 'in:' . implode(',', array_keys(LmsQuestion::TYPE_LABELS))],
            'question_text' => ['required', 'string'],
            'context_text'  => ['nullable', 'string'],
            'explanation'   => ['nullable', 'string'],
            'points'        => ['required', 'integer', 'min:0'],
            'options'       => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct'  => ['boolean'],
            'options.*.rating_value'=> ['nullable', 'integer'],
        ]);

        $question = $bank->questions()->create([
            'type'          => $request->type,
            'question_text' => $request->question_text,
            'context_text'  => $request->context_text,
            'explanation'   => $request->explanation,
            'points'        => $request->points,
            'sort_order'    => $bank->questions()->max('sort_order') + 1,
        ]);

        if ($request->has('options') && in_array($request->type, LmsQuestion::HAS_OPTIONS)) {
            foreach ($request->options as $i => $opt) {
                $question->options()->create([
                    'option_text'  => $opt['option_text'],
                    'is_correct'   => $opt['is_correct'] ?? false,
                    'rating_value' => $opt['rating_value'] ?? null,
                    'sort_order'   => $i,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Question added to bank.');
    }

    public function destroyBankQuestion(LmsQuestionBank $bank, LmsQuestion $question): RedirectResponse
    {
        abort_unless($question->bank_id === $bank->id, 404);
        $question->options()->delete();
        $question->delete();

        return redirect()->back()->with('success', 'Question deleted from bank.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatQuestion(LmsQuestion $q): array
    {
        return [
            'id'            => $q->id,
            'uuid'          => $q->uuid,
            'type'          => $q->type,
            'type_label'    => LmsQuestion::TYPE_LABELS[$q->type] ?? $q->type,
            'question_text' => $q->question_text,
            'context_text'  => $q->context_text,
            'explanation'   => $q->explanation,
            'points'        => $q->points,
            'sort_order'    => $q->sort_order,
            'has_options'   => $q->hasOptions(),
            'is_auto_graded'=> $q->isAutoGraded(),
            'options'       => $q->options->map(fn ($o) => [
                'id'           => $o->id,
                'option_text'  => $o->option_text,
                'is_correct'   => $o->is_correct,
                'rating_value' => $o->rating_value,
                'sort_order'   => $o->sort_order,
            ]),
        ];
    }
}
