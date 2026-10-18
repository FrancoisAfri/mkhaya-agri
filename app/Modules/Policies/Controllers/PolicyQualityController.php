<?php

namespace App\Modules\Policies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyConflict;
use App\Modules\Policies\Models\PolicyQualityAnalysis;
use App\Modules\Policies\Services\ConflictDetectionService;
use App\Modules\Policies\Services\PolicyQualityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyQualityController extends Controller
{
    public function __construct(
        private readonly ConflictDetectionService $conflictService,
        private readonly PolicyQualityService     $qualityService,
    ) {}

    // ── Main page ─────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $policies = Policy::select('id', 'name', 'status', 'category_id', 'department_id')
            ->orderBy('name')
            ->get();

        $conflicts = $this->loadConflicts();
        $analyses  = $this->loadAnalyses();

        return Inertia::render('Policies/Quality/Index', [
            'policies'  => $policies,
            'conflicts' => $conflicts,
            'analyses'  => $analyses,
        ]);
    }

    // ── Conflict detection ────────────────────────────────────────────────────

    public function detectConflicts(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policy,id',
        ]);

        $policy  = Policy::findOrFail($validated['policy_id']);
        $newOnes = $this->conflictService->detect($policy, $request->user()->id);

        $count = count($newOnes);
        $msg   = $count > 0
            ? "Detected {$count} new conflict(s) for \"{$policy->name}\"."
            : "No new conflicts detected for \"{$policy->name}\".";

        return redirect()->route('policy-quality.index')->with('success', $msg);
    }

    // ── Conflict status management ────────────────────────────────────────────

    public function acknowledgeConflict(PolicyConflict $conflict): RedirectResponse
    {
        $conflict->update(['status' => 'acknowledged']);

        return redirect()->route('policy-quality.index')->with('success', 'Conflict acknowledged.');
    }

    public function resolveConflict(Request $request, PolicyConflict $conflict): RedirectResponse
    {
        $conflict->update([
            'status'      => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return redirect()->route('policy-quality.index')->with('success', 'Conflict marked as resolved.');
    }

    public function falsePositiveConflict(PolicyConflict $conflict): RedirectResponse
    {
        $conflict->update(['status' => 'false_positive']);

        return redirect()->route('policy-quality.index')->with('success', 'Conflict flagged as false positive.');
    }

    // ── AI quality analysis ───────────────────────────────────────────────────

    public function suggest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policy,id',
        ]);

        $policy   = Policy::findOrFail($validated['policy_id']);
        $analysis = $this->qualityService->analyse($policy, $request->user()->id);

        $msg = $analysis->status === 'completed'
            ? "AI analysis complete — {$analysis->issue_count} suggestion(s) found for \"{$policy->name}\"."
            : "AI analysis failed for \"{$policy->name}\". Check logs.";

        return redirect()->route('policy-quality.index')->with(
            $analysis->status === 'completed' ? 'success' : 'error',
            $msg,
        );
    }

    // ── Data loaders ──────────────────────────────────────────────────────────

    private function loadConflicts(): array
    {
        return PolicyConflict::with([
                'policyA:id,name',
                'policyB:id,name',
                'ruleA:id,name',
                'ruleB:id,name',
            ])
            ->whereIn('status', ['open', 'acknowledged'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn(PolicyConflict $c) => [
                'id'            => $c->id,
                'policy_a'      => optional($c->policyA)->name ?? '—',
                'policy_b'      => optional($c->policyB)->name ?? '—',
                'rule_a'        => optional($c->ruleA)->name,
                'rule_b'        => optional($c->ruleB)->name,
                'conflict_type' => $c->conflict_type,
                'description'   => $c->description,
                'status'        => $c->status,
                'created_at'    => $c->created_at->toDateTimeString(),
            ])
            ->toArray();
    }

    private function loadAnalyses(): array
    {
        return PolicyQualityAnalysis::with('policy:id,name')
            ->where('status', 'completed')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn(PolicyQualityAnalysis $a) => [
                'id'          => $a->id,
                'policy_name' => optional($a->policy)->name ?? '—',
                'status'      => $a->status,
                'issue_count' => $a->issue_count,
                'summary'     => $a->summary,
                'suggestions' => $a->suggestions ?? [],
                'completed_at'=> $a->completed_at?->toDateTimeString(),
            ])
            ->toArray();
    }
}
