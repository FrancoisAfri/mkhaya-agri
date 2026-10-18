<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierIndustryType;
use App\Modules\SupplierManagement\Models\SupplierPerformanceTemplate;
use App\Modules\SupplierManagement\Models\SupplierQuestionLibrary;
use App\Modules\SupplierManagement\Services\SupplierAppraisalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier performance: the appraisals themselves, and the templates they are scored against.
 *
 * These used to be one screen. It listed every appraisal ever done, offered a box to start a new
 * one, a box to create a template, and a box to add a question to a template — four unrelated jobs
 * stacked down a page, with no way to edit or remove any of it afterwards. Designing a scorecard
 * and filling one in are different tasks done by different people at different times, and a form
 * that creates something it cannot then change is a one-way door.
 *
 * They are separate now: the appraisals, one appraisal, the templates, and one template's builder.
 */
class SupplierAppraisalController extends Controller
{
    public function __construct(private readonly SupplierAppraisalService $appraisals) {}

    /**
     * What has been appraised, and how it is trending.
     *
     * Paginated and filterable rather than `->get()` on everything, because this is the one screen
     * that grows without limit — a hundred suppliers appraised quarterly is four hundred rows a year
     * — and the question somebody actually brings to it is about one supplier or one quarter.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only('supplier_id', 'template_id', 'industry_id', 'from', 'to');

        $appraisals = SupplierAppraisal::query()
            ->with(['supplier:id,uuid,company_name', 'template:id,uuid,name', 'industry:id,industry_type_name'])
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['template_id'] ?? null, fn ($q, $v) => $q->where('template_id', $v))
            ->when($filters['industry_id'] ?? null, fn ($q, $v) => $q->where('industry_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('appraisal_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('appraisal_date', '<=', $v))
            ->latest('appraisal_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierAppraisal $a) => [
                'uuid' => $a->uuid,
                'supplier' => $a->supplier?->company_name,
                'supplier_uuid' => $a->supplier?->uuid,
                'template' => $a->template?->name,
                'industry' => $a->industry?->industry_type_name,
                'date' => $a->appraisal_date?->toDateString(),
                'score' => $a->total_score === null ? null : round((float) $a->total_score, 1),
                'status' => $a->status,
            ]);

        return Inertia::render('Modules/SupplierManagement/Appraisals/Index', [
            'appraisals' => $appraisals,
            'filters' => $filters,
            'stats' => $this->stats(),
            'trend' => $this->trend(),
            'templates' => SupplierPerformanceTemplate::query()->orderBy('name')->get(['id', 'uuid', 'name']),
            'industries' => SupplierIndustryType::where('active', true)->orderBy('industry_type_name')->get(['id', 'industry_type_name']),
            'suppliers' => Supplier::active()->orderBy('company_name')->get(['id', 'uuid', 'company_name']),
        ]);
    }

    /**
     * One appraisal, question by question.
     *
     * The thing the old screen could not do at all: a total score with no way to see what produced
     * it settles an argument by assertion. Each answer is shown with the weight it carried and
     * whether it cleared the question's own minimum, because a respectable average can hide a
     * failed safety question underneath it.
     */
    public function show(SupplierAppraisal $appraisal): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $appraisal->load([
            'supplier:id,uuid,company_name',
            'template:id,uuid,name',
            'industry:id,industry_type_name',
            'responses.question',
        ]);

        return Inertia::render('Modules/SupplierManagement/Appraisals/Show', [
            'appraisal' => [
                'uuid' => $appraisal->uuid,
                'supplier' => $appraisal->supplier?->company_name,
                'supplier_uuid' => $appraisal->supplier?->uuid,
                'template' => $appraisal->template?->name,
                'template_uuid' => $appraisal->template?->uuid,
                'industry' => $appraisal->industry?->industry_type_name,
                'date' => $appraisal->appraisal_date?->toDateString(),
                'status' => $appraisal->status,
                'score' => $appraisal->total_score === null ? null : round((float) $appraisal->total_score, 1),
            ],
            'responses' => $appraisal->responses->map(fn ($r) => [
                'id' => $r->id,
                'question' => $r->question?->question,
                'description' => $r->question?->description,
                'weight' => $r->question === null ? null : (float) $r->question->weight_percentage,
                'minimum' => $r->question?->minimum_threshold,
                // Null on anything appraised before the rating was kept. Shown as "not recorded"
                // rather than as a zero, which would read as the worst possible answer.
                'rating' => $r->rating,
                'score' => $r->score === null ? null : round((float) $r->score, 2),
                'outcome' => $r->response,
                'comment' => $r->comment,
            ])->values(),
        ]);
    }

    /** The scorecards themselves: what exists, for which industry, and whether each one adds up. */
    public function templates(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $templates = SupplierPerformanceTemplate::query()
            ->with(['questions:id,template_id,weight_percentage', 'industry:id,industry_type_name'])
            ->withCount('questions')
            ->orderBy('name')
            ->get()
            ->map(fn (SupplierPerformanceTemplate $t) => [
                'uuid' => $t->uuid,
                'name' => $t->name,
                'status' => $t->status,
                'industry' => $t->industry?->industry_type_name,
                'questions' => $t->questions_count,
                // The number that decides whether a template can be used honestly: weights that do
                // not reach 100 silently cap every supplier's score below full marks.
                'weight' => round((float) $t->questions->sum('weight_percentage'), 2),
            ])
            ->values();

        return Inertia::render('Modules/SupplierManagement/Appraisals/Templates', [
            'templates' => $templates,
            'industries' => SupplierIndustryType::where('active', true)->orderBy('industry_type_name')->get(['id', 'industry_type_name']),
        ]);
    }

    /** One template, and the questions it asks. */
    public function template(SupplierPerformanceTemplate $template): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $template->load(['industry:id,industry_type_name']);

        $questions = SupplierQuestionLibrary::query()
            ->where('template_id', $template->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierQuestionLibrary $q) => [
                'uuid' => $q->uuid,
                'question' => $q->question,
                'description' => $q->description,
                'weight' => (float) $q->weight_percentage,
                'minimum' => $q->minimum_threshold,
                'sort_order' => $q->sort_order,
            ])
            ->values();

        return Inertia::render('Modules/SupplierManagement/Appraisals/TemplateBuilder', [
            'template' => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'status' => $template->status,
                'industry_id' => $template->industry_id,
                'industry' => $template->industry?->industry_type_name,
            ],
            'questions' => $questions,
            'weight' => round((float) $questions->sum('weight'), 2),
            'industries' => SupplierIndustryType::where('active', true)->orderBy('industry_type_name')->get(['id', 'industry_type_name']),
            'usedBy' => SupplierAppraisal::where('template_id', $template->id)->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $scores = SupplierAppraisal::query()->whereNotNull('total_score');

        return [
            'total' => SupplierAppraisal::count(),
            'last_90' => SupplierAppraisal::where('appraisal_date', '>=', now()->subDays(90))->count(),
            // Null, not zero, when nothing has been appraised: "no appraisals yet" and "everybody
            // scored nothing" are the same number on a dashboard and opposite facts.
            'average' => $scores->clone()->count() === 0 ? null : round((float) $scores->clone()->avg('total_score'), 1),
            'below_50' => $scores->clone()->where('total_score', '<', 50)->count(),
            'templates' => SupplierPerformanceTemplate::count(),
        ];
    }

    /**
     * Average score by month, for the last twelve.
     *
     * Months with nothing appraised are absent rather than zero — a gap in a line is honest about a
     * quarter nobody got round to, and a zero is a cliff that reads as a collapse in performance.
     *
     * @return list<array{month: string, average: float, count: int}>
     */
    private function trend(): array
    {
        return SupplierAppraisal::query()
            ->selectRaw("to_char(appraisal_date, 'YYYY-MM') as month, avg(total_score) as average, count(*) as count")
            ->whereNotNull('total_score')
            ->where('appraisal_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => (string) $row->month,
                'average' => round((float) $row->average, 1),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    public function templatesStore(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'industry_id' => 'nullable|integer',
            'status'      => 'nullable|string|max:255',
        ]);

        $template = SupplierPerformanceTemplate::create($data);

        // Straight into the builder: a template with no questions cannot appraise anything, and
        // leaving somebody on a list is how empty templates accumulate.
        return redirect()
            ->route('suppliers.appraisals.templates.show', $template)
            ->with('success', __('supplier_management.appraisals.flash.template_created'));
    }

    public function templatesUpdate(Request $request, SupplierPerformanceTemplate $template): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $template->update($request->validate([
            'name'        => 'required|string|max:255',
            'industry_id' => 'nullable|integer|exists:supplier_industry_types,id',
            'status'      => 'nullable|string|max:255',
        ]));

        return back()->with('success', __('supplier_management.appraisals.flash.template_saved'));
    }

    /**
     * Remove a template, unless an appraisal was scored against it.
     *
     * A past appraisal's questions, weights and thresholds are read through its template. Deleting
     * one would leave every appraisal that used it showing a score with nothing behind it, which is
     * the same as losing the appraisal — so a template in use is retired rather than removed.
     */
    public function templatesDestroy(SupplierPerformanceTemplate $template): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        if (SupplierAppraisal::where('template_id', $template->id)->exists()) {
            return back()->with('error', __('supplier_management.appraisals.flash.template_in_use'));
        }

        $template->questions()->delete();
        $template->delete();

        return redirect()
            ->route('suppliers.appraisals.templates')
            ->with('success', __('supplier_management.appraisals.flash.template_removed'));
    }

    public function questionStore(Request $request, SupplierPerformanceTemplate $template): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'question'          => 'required|string|max:255',
            'description'       => 'nullable|string',
            'weight_percentage' => 'nullable|numeric|min:1|max:100',
            'minimum_threshold' => 'nullable|integer|min:1|max:5',
        ]);

        $this->assertWeightFits($template, (float) ($data['weight_percentage'] ?? 0));

        SupplierQuestionLibrary::create($data + [
            'template_id' => $template->id,
            // Appended, so a question lands where the person adding it expects: at the bottom.
            'sort_order' => (int) SupplierQuestionLibrary::where('template_id', $template->id)->max('sort_order') + 1,
        ]);

        return back()->with('success', __('supplier_management.appraisals.flash.question_added'));
    }

    public function questionUpdate(Request $request, SupplierPerformanceTemplate $template, SupplierQuestionLibrary $question): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        abort_unless((int) $question->template_id === (int) $template->id, 404);

        $data = $request->validate([
            'question'          => 'required|string|max:255',
            'description'       => 'nullable|string',
            'weight_percentage' => 'nullable|numeric|min:1|max:100',
            'minimum_threshold' => 'nullable|integer|min:1|max:5',
            'sort_order'        => 'nullable|integer|min:0|max:9999',
        ]);

        // Its own current weight does not count against the ceiling, or a question could never be
        // edited without first being deleted.
        $this->assertWeightFits($template, (float) ($data['weight_percentage'] ?? 0), $question->id);

        $question->update($data);

        return back()->with('success', __('supplier_management.appraisals.flash.question_saved'));
    }

    public function questionDestroy(SupplierPerformanceTemplate $template, SupplierQuestionLibrary $question): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        abort_unless((int) $question->template_id === (int) $template->id, 404);

        $question->delete();

        return back()->with('success', __('supplier_management.appraisals.flash.question_removed'));
    }

    /**
     * A template's weights may total 100 and no more.
     *
     * Over 100 lets a supplier score above full marks. Under 100 is allowed while a template is
     * being built — refusing it would make the first question impossible to add — so the screen
     * reports the shortfall instead, because weights that never reach 100 silently cap every
     * supplier below full marks and nothing else would say so.
     */
    private function assertWeightFits(SupplierPerformanceTemplate $template, float $weight, ?int $excludingId = null): void
    {
        $existing = (float) SupplierQuestionLibrary::where('template_id', $template->id)
            ->when($excludingId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->sum('weight_percentage');

        if ($existing + $weight > 100) {
            throw ValidationException::withMessages([
                'weight_percentage' => [__('supplier_management.appraisals.flash.weight_over', [
                    'remaining' => round(100 - $existing, 2),
                ])],
            ]);
        }
    }

    public function appraiseForm(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        /*
         * The industry decides which template's questions apply, so an empty list is a dead end: the
         * screen offered the supplier's own industries and nothing else, and a supplier with none
         * linked — which is every supplier until somebody edits them — got "No data available" above
         * a template picker that could not be used.
         *
         * Their own industries first, because that is what the appraisal should be measured against.
         * Everything active when they have none, so the work can proceed, with a flag so the screen
         * can say which of the two it is showing rather than letting somebody assume.
         */
        $own = $supplier->industries()->get(['supplier_industry_types.id', 'industry_type_name']);
        $falling_back = $own->isEmpty();

        return Inertia::render('Modules/SupplierManagement/Appraisals/Appraise', [
            'supplier'   => $supplier->only(['id', 'uuid', 'company_name']),
            'industries' => $falling_back
                ? SupplierIndustryType::query()->where('active', true)
                    ->orderBy('industry_type_name')->get(['id', 'industry_type_name'])
                : $own,
            'industriesAreFallback' => $falling_back,
            'templates'  => SupplierPerformanceTemplate::with('questions')->get(),
        ]);
    }

    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('view', $supplier);

        $data = $request->validate([
            'industry_id'                    => 'nullable|integer',
            'template_id'                    => 'nullable|integer',
            'answers'                        => 'required|array|min:1',
            'answers.*.question_library_id'  => 'required|integer',
            'answers.*.rating'               => 'required|integer|min:1|max:5',
            'answers.*.comment'              => 'nullable|string',
        ]);

        $this->appraisals->store(
            $supplier->id,
            $data['industry_id'] ?? null,
            $data['template_id'] ?? null,
            Auth::id(),
            $data['answers']
        );

        return redirect()->route('suppliers.appraisals.index')
            ->with('success', 'Appraisal recorded.');
    }
}
