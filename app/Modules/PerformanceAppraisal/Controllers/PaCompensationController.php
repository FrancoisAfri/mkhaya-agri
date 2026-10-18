<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCompRecommendation;
use App\Modules\PerformanceAppraisal\Models\PaCompReview;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PaCompensationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaCompensationController extends Controller
{
    public function __construct(private PaCompensationService $service) {}

    public function index(Request $request): Response
    {
        $reviews = PaCompReview::with('cycle')->withCount('recommendations')->latest()->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'name'      => $r->name,
                'cycle'     => $r->cycle?->name,
                'status'    => $r->status,
                'count'     => $r->recommendations_count,
                'created_at'=> $r->created_at?->toDateString(),
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Compensation/Index', [
            'reviews' => $reviews,
            'cycles'  => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'matrix'  => PaCompensationService::MATRIX,
        ]);
    }

    public function show(PaCompReview $compReview): Response
    {
        $compReview->load('cycle');
        $recs = $compReview->recommendations()->get();

        $people = DB::table('hr_people')->whereIn('user_id', $recs->pluck('employee_id'))
            ->get(['user_id', 'first_name', 'surname', 'position'])->keyBy('user_id');

        $rows = $recs->map(function ($r) use ($people) {
            $p = $people->get($r->employee_id);
            return [
                'id'                  => $r->id,
                'employee_id'         => $r->employee_id,
                'name'                => $p ? trim("{$p->first_name} {$p->surname}") : ('User #' . $r->employee_id),
                'position'            => $p->position ?? null,
                'performance_score'   => $r->performance_score,
                'rating_band'         => $r->rating_band,
                'current_salary'      => $r->current_salary,
                'suggested_merit_pct' => $r->suggested_merit_pct,
                'final_merit_pct'     => $r->final_merit_pct,
                'suggested_bonus_pct' => $r->suggested_bonus_pct,
                'final_bonus_pct'     => $r->final_bonus_pct,
                'new_salary'          => $r->new_salary,
                'bonus_amount'        => $r->bonus_amount,
                'status'              => $r->status,
            ];
        })->values();

        return Inertia::render('Modules/PerformanceAppraisal/Compensation/Show', [
            'review'  => [
                'id' => $compReview->id, 'name' => $compReview->name, 'status' => $compReview->status,
                'cycle' => $compReview->cycle?->name, 'merit_budget_pct' => $compReview->merit_budget_pct,
                'bonus_budget_pct' => $compReview->bonus_budget_pct,
            ],
            'rows'    => $rows,
            'totals'  => $this->service->totals($compReview),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cycle_id'         => 'required|exists:pa_cycles,id',
            'name'             => 'required|string|max:150',
            'merit_budget_pct' => 'nullable|numeric|min:0|max:100',
            'bonus_budget_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $review = PaCompReview::create($data + ['status' => 'draft', 'created_by' => Auth::id()]);
        $this->service->generate($review);

        return redirect()->route('performance.compensation.show', $review->id)
            ->with('success', 'Compensation review created and recommendations generated.');
    }

    public function regenerate(PaCompReview $compReview): RedirectResponse
    {
        $n = $this->service->generate($compReview);
        return back()->with('success', "Regenerated {$n} recommendations from latest scores.");
    }

    public function updateRecommendation(Request $request, PaCompRecommendation $recommendation): RedirectResponse
    {
        $data = $request->validate([
            'final_merit_pct' => 'nullable|numeric|min:0|max:100',
            'final_bonus_pct' => 'nullable|numeric|min:0|max:100',
            'status'          => 'nullable|in:pending,approved,rejected',
            'justification'   => 'nullable|string|max:1000',
        ]);

        $recommendation->fill($data);
        $recommendation->save();
        $this->service->recalc($recommendation);

        return back()->with('success', 'Recommendation updated.');
    }

    public function approve(PaCompReview $compReview): RedirectResponse
    {
        $compReview->update([
            'status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(),
        ]);

        return back()->with('success', 'Compensation review approved.');
    }
}
