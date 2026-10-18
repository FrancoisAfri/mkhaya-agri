<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaRatingScale;
use App\Modules\PerformanceAppraisal\Models\PaTemplate;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PaCycleController extends Controller
{
    public function __construct(private PerformanceAppraisalPolicyGuard $policyGuard) {}

    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Cycles/Index', [
            'cycles'       => PaCycle::with(['ratingScale', 'template', 'creator'])
                ->orderByDesc('start_date')
                ->get()
                ->map(fn($c) => [
                    'id'                      => $c->id,
                    'name'                    => $c->name,
                    'description'             => $c->description,
                    'cycle_type'              => $c->cycle_type,
                    'start_date'              => $c->start_date?->format('Y-m-d'),
                    'end_date'                => $c->end_date?->format('Y-m-d'),
                    'self_review_deadline'    => $c->self_review_deadline?->format('Y-m-d'),
                    'manager_review_deadline' => $c->manager_review_deadline?->format('Y-m-d'),
                    'status'                  => $c->status,
                    'allow_self_review'       => $c->allow_self_review,
                    'allow_peer_review'       => $c->allow_peer_review,
                    'require_manager_approval'=> $c->require_manager_approval,
                    'rating_scale_id'         => $c->rating_scale_id,
                    'template_id'             => $c->template_id,
                    'rating_scale'            => $c->ratingScale?->only('id', 'name'),
                    'template'                => $c->template?->only('id', 'name'),
                    'created_by_name'         => optional($c->creator)->email,
                ]),
            'ratingScales' => PaRatingScale::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'templates'    => PaTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        PaCycle::create($data);

        return back()->with('success', 'Appraisal cycle created.');
    }

    public function update(Request $request, PaCycle $cycle): RedirectResponse
    {
        $cycle->update($this->validated($request, isUpdate: true));

        return back()->with('success', 'Appraisal cycle updated.');
    }

    public function destroy(PaCycle $cycle): RedirectResponse
    {
        $cycle->delete();

        return back()->with('success', 'Appraisal cycle deleted.');
    }

    public function activate(PaCycle $cycle): RedirectResponse
    {
        $this->policyGuard->forCycleStatusChanged($cycle->id, 'active', Auth::id());
        $cycle->update(['status' => 'active']);

        return back()->with('success', 'Cycle activated.');
    }

    public function close(PaCycle $cycle): RedirectResponse
    {
        $this->policyGuard->forCycleStatusChanged($cycle->id, 'closed', Auth::id());
        $cycle->update(['status' => 'closed']);

        return back()->with('success', 'Cycle closed.');
    }

    private function validated(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name'                    => [$required, 'string', 'max:150'],
            'description'             => ['nullable', 'string'],
            'cycle_type'              => [$required, 'in:annual,semi_annual,quarterly,monthly,probation,custom'],
            'start_date'              => [$required, 'date'],
            'end_date'                => [$required, 'date', 'after_or_equal:start_date'],
            'self_review_deadline'    => ['nullable', 'date'],
            'manager_review_deadline' => ['nullable', 'date'],
            'status'                  => ['sometimes', 'in:draft,active,closed,archived'],
            'rating_scale_id'         => ['nullable', 'exists:pa_rating_scales,id'],
            'template_id'             => ['nullable', 'exists:pa_templates,id'],
            'allow_self_review'       => ['boolean'],
            'allow_peer_review'       => ['boolean'],
            'require_manager_approval'=> ['boolean'],
        ]);
    }
}
