<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\Pa360Question;
use App\Modules\PerformanceAppraisal\Models\Pa360Rater;
use App\Modules\PerformanceAppraisal\Models\Pa360Response;
use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Notifications\Pa360ReviewNotification;
use App\Modules\PerformanceAppraisal\Services\Pa360ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class Pa360ReviewController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use SafeNotifies;

    public function __construct(private Pa360ReportService $reporter) {}

    // ── Reviews list ──────────────────────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/360/Index', [
            'reviews'   => Pa360Review::with(['employee', 'cycle'])
                ->withCount(['raters', 'raters as submitted_count' => fn($q) => $q->where('status', 'submitted')])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($r) => [
                    'id'              => $r->id,
                    'title'           => $r->title,
                    'employee_id'     => $r->employee_id,
                    'employee_name'   => $r->employee->email ?? '-',
                    'cycle_name'      => $r->cycle?->name,
                    'due_date'        => $r->due_date?->format('Y-m-d'),
                    'status'          => $r->status,
                    'raters_count'    => $r->raters_count,
                    'submitted_count' => $r->submitted_count,
                    'completion_rate' => $r->completionRate(),
                    'require_anonymity' => $r->require_anonymity,
                    'has_ai_summary'  => !empty($r->ai_summary),
                    'cycle_id'        => $r->cycle_id,
                ]),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList(),
            'questions' => Pa360Question::where('is_active', true)->orderBy('category')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'      => ['required', 'exists:users,id'],
            'cycle_id'         => ['nullable', 'exists:pa_cycles,id'],
            'title'            => ['required', 'string', 'max:200'],
            'instructions'     => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'require_anonymity'=> ['boolean'],
            'min_raters'       => ['integer', 'min:1'],
            'max_raters'       => ['integer', 'min:1'],
            'question_ids'     => ['nullable', 'array'],
            'question_ids.*'   => ['integer', 'exists:pa_360_questions,id'],
        ]);
        $data['created_by'] = $request->user()->id;

        $review360 = Pa360Review::create($data);

        $employee = User::find($review360->employee_id);
        if ($employee) {
            $this->safeNotify($employee, new Pa360ReviewNotification($review360, 'review_created'));
        }

        return back()->with('success', '360 review created.');
    }

    public function update(Request $request, Pa360Review $review): RedirectResponse
    {
        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:200'],
            'instructions'     => ['nullable', 'string'],
            'due_date'         => ['nullable', 'date'],
            'require_anonymity'=> ['boolean'],
            'min_raters'       => ['integer', 'min:1'],
            'max_raters'       => ['integer', 'min:1'],
            'status'           => ['sometimes', 'in:draft,collecting,closed,reported'],
            'question_ids'     => ['nullable', 'array'],
            'question_ids.*'   => ['integer', 'exists:pa_360_questions,id'],
        ]);

        $review->update($data);

        return back()->with('success', '360 review updated.');
    }

    public function destroy(Pa360Review $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', '360 review deleted.');
    }

    // ── Single review page ────────────────────────────────────────────────────

    public function show(Pa360Review $review): Response
    {
        $review->load(['employee', 'cycle', 'raters.rater']);

        return Inertia::render('Modules/PerformanceAppraisal/360/Review', [
            'review'    => [
                'id'               => $review->id,
                'title'            => $review->title,
                'instructions'     => $review->instructions,
                'due_date'         => $review->due_date?->format('Y-m-d'),
                'status'           => $review->status,
                'require_anonymity'=> $review->require_anonymity,
                'ai_summary'       => $review->ai_summary,
                'employee_id'      => $review->employee_id,
                'employee_name'    => $review->employee->email ?? '-',
                'cycle_name'       => $review->cycle?->name,
                'completion_rate'  => $review->completionRate(),
            ],
            'raters'    => $review->raters->map(fn($r) => [
                'id'           => $r->id,
                'rater_id'     => $r->rater_id,
                'rater_name'   => $r->rater->email ?? '-',
                'relationship' => $r->relationship,
                'status'       => $r->status,
                'submitted_at' => $r->submitted_at?->format('d M Y H:i'),
            ]),
            'questions'  => Pa360Question::whereIn(
                'id',
                $review->question_ids ?? Pa360Question::where('is_active', true)->pluck('id')->toArray()
            )->orderBy('category')->orderBy('sort_order')->get(),
            'employees'  => $this->personList(),
            'report'     => $review->status !== 'draft' ? $this->reporter->buildReport($review) : null,
        ]);
    }

    // ── Rater management ──────────────────────────────────────────────────────

    public function addRater(Request $request, Pa360Review $review): RedirectResponse
    {
        $data = $request->validate([
            'rater_id'    => ['required', 'exists:users,id'],
            'relationship'=> ['required', 'in:self,manager,peer,direct_report,external'],
        ]);

        if ($review->raters()->where('rater_id', $data['rater_id'])->exists()) {
            return back()->withErrors(['rater_id' => 'This person is already a rater.']);
        }

        $review->raters()->create([
            ...$data,
            'invited_at'           => now(),
            'nominated_by_employee'=> false,
        ]);

        $raterUser = User::find($data['rater_id']);
        if ($raterUser) {
            $this->safeNotify($raterUser, new Pa360ReviewNotification($review, 'added_as_rater', $data['relationship']));
        }

        return back()->with('success', 'Rater added.');
    }

    public function removeRater(Pa360Review $review, Pa360Rater $rater): RedirectResponse
    {
        $rater->delete();

        return back()->with('success', 'Rater removed.');
    }

    public function openCollection(Pa360Review $review): RedirectResponse
    {
        $review->update(['status' => 'collecting']);

        return back()->with('success', 'Review opened — raters can now submit responses.');
    }

    public function closeCollection(Pa360Review $review): RedirectResponse
    {
        $review->update(['status' => 'closed']);

        return back()->with('success', 'Collection closed.');
    }

    // ── Rater response submission ──────────────────────────────────────────────

    public function respond(Pa360Rater $rater): Response
    {
        $review = $rater->review()->with('employee')->first();
        $questions = Pa360Question::whereIn(
            'id',
            $review->question_ids ?? Pa360Question::where('is_active', true)->pluck('id')->toArray()
        )->orderBy('category')->orderBy('sort_order')->get();

        $existing = Pa360Response::where('rater_id', $rater->id)
            ->get()
            ->keyBy('question_id');

        return Inertia::render('Modules/PerformanceAppraisal/360/Respond', [
            'rater'     => ['id' => $rater->id, 'relationship' => $rater->relationship, 'status' => $rater->status],
            'review'    => [
                'id'               => $review->id,
                'title'            => $review->title,
                'instructions'     => $review->instructions,
                'employee_name'    => $review->employee->email ?? '-',
                'require_anonymity'=> $review->require_anonymity,
            ],
            'questions' => $questions,
            'existing'  => $existing->map(fn($r) => ['rating_value' => $r->rating_value, 'text_value' => $r->text_value]),
        ]);
    }

    public function submitResponse(Request $request, Pa360Rater $rater): RedirectResponse
    {
        $review = $rater->review;

        if ($review->status !== 'collecting') {
            return back()->withErrors(['status' => 'This review is not currently open for responses.']);
        }

        $data = $request->validate([
            'responses'              => ['required', 'array'],
            'responses.*.question_id'=> ['required', 'exists:pa_360_questions,id'],
            'responses.*.rating'     => ['nullable', 'numeric', 'min:0', 'max:5'],
            'responses.*.comment'    => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($data, $rater) {
            Pa360Response::where('rater_id', $rater->id)->delete();

            foreach ($data['responses'] as $order => $item) {
                $question = Pa360Question::find($item['question_id']);

                Pa360Response::create([
                    'review_id'         => $rater->review_id,
                    'rater_id'          => $rater->id,
                    'question_id'       => $item['question_id'],
                    'question_snapshot' => $question?->question ?? '',
                    'category'          => $question?->category ?? 'general',
                    'rating_value'      => $item['rating'] ?? null,
                    'text_value'        => $item['comment'] ?? null,
                    'sort_order'        => $order,
                ]);
            }

            $rater->update(['status' => 'submitted', 'submitted_at' => now()]);
        });

        return redirect()->route('performance.360.index')
            ->with('success', 'Your 360 feedback has been submitted. Thank you.');
    }

    // ── AI report ─────────────────────────────────────────────────────────────

    public function generateReport(Pa360Review $review): RedirectResponse
    {
        $summary = $this->reporter->generateAiSummary($review);
        $review->update(['status' => 'reported', 'ai_summary' => $summary]);

        return back()->with('success', 'AI report generated.');
    }

    // ── Question bank ─────────────────────────────────────────────────────────

    public function questionStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'question'      => ['required', 'string', 'max:500'],
            'category'      => ['required', 'in:leadership,teamwork,communication,technical,culture,general'],
            'question_type' => ['required', 'in:rating,text,both'],
            'is_active'     => ['boolean'],
            'sort_order'    => ['integer'],
        ]);

        Pa360Question::create($data);

        return back()->with('success', 'Question added to bank.');
    }

    public function questionUpdate(Request $request, Pa360Question $question): RedirectResponse
    {
        $data = $request->validate([
            'question'      => ['sometimes', 'string', 'max:500'],
            'category'      => ['sometimes', 'in:leadership,teamwork,communication,technical,culture,general'],
            'question_type' => ['sometimes', 'in:rating,text,both'],
            'is_active'     => ['boolean'],
            'sort_order'    => ['integer'],
        ]);

        $question->update($data);

        return back()->with('success', 'Question updated.');
    }

    public function questionDestroy(Pa360Question $question): RedirectResponse
    {
        $question->delete();

        return back()->with('success', 'Question deleted.');
    }

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
}
