<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\RunComplianceCheckAction;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetComplianceRule;
use App\Modules\Assets\Models\AssetComplianceRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController extends Controller
{
    public function dashboard(): Response
    {
        $latestRun = AssetComplianceRun::orderByDesc('run_at')->first();

        $trend = AssetComplianceRun::orderByDesc('run_at')
            ->limit(10)
            ->get(['id', 'compliance_rate', 'pass_count', 'fail_count', 'overdue_count', 'run_at'])
            ->reverse()
            ->values();

        return Inertia::render('Modules/Assets/Compliance/Dashboard', [
            'latest_run' => $latestRun,
            'trend'      => $trend,
        ]);
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    public function rulesIndex(): Response
    {
        $rules = AssetComplianceRule::with('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('Modules/Assets/Compliance/Rules/Index', [
            'rules'      => $rules,
            'categories' => AssetCategory::where('status', 1)->get(['id', 'name']),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'nullable|string|max:2000',
            'rule_type'          => 'required|string|in:maintenance_interval,software_check,inspection,certification,other',
            'applies_to'         => 'required|string|in:all,category',
            'asset_category_id'  => 'required_if:applies_to,category|nullable|integer|exists:asset_categories,id',
            'interval_days'      => 'required|integer|min:1',
            'grace_period_days'  => 'integer|min:0',
        ]);

        if ($data['applies_to'] === 'all') {
            $data['asset_category_id'] = null;
        }

        AssetComplianceRule::create(array_merge($data, ['created_by' => $request->user()->id, 'is_active' => true]));

        return back()->with('success', 'Compliance rule created.');
    }

    public function updateRule(Request $request, AssetComplianceRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'description'       => 'nullable|string|max:2000',
            'interval_days'     => 'sometimes|integer|min:1',
            'grace_period_days' => 'sometimes|integer|min:0',
            'is_active'         => 'sometimes|boolean',
        ]);

        $rule->update($data);

        return back()->with('success', 'Rule updated.');
    }

    public function destroyRule(AssetComplianceRule $rule): RedirectResponse
    {
        $rule->delete();
        return back()->with('success', 'Rule deleted.');
    }

    // ── Runs ──────────────────────────────────────────────────────────────────

  public function runsIndex(): Response
{
    $runs = AssetComplianceRun::with('runBy')
        ->orderByDesc('run_at')
        ->paginate(25);

    return Inertia::render('Modules/Assets/Compliance/Runs/Index', [
        'runs' => [
            'data' => $runs->items(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page'    => $runs->lastPage(),
                'total'        => $runs->total(),
            ],
            'links' => [
                'prev' => $runs->previousPageUrl(),
                'next' => $runs->nextPageUrl(),
            ],
        ],
    ]);
}

    public function storeRun(Request $request, RunComplianceCheckAction $action): RedirectResponse
    {
        $data = $request->validate([
            'scope'             => 'required|string|in:all,category',
            'asset_category_id' => 'required_if:scope,category|nullable|integer|exists:asset_categories,id',
        ]);

        $run = $action->execute($data, $request->user()->id);

        return redirect()->route('assets.compliance-runs.show', $run->uuid)
            ->with('success', "Compliance check complete. Rate: {$run->compliance_rate}%");
    }

    public function showRun(AssetComplianceRun $complianceRun, Request $request): Response
    {
        $complianceRun->load('runBy');

        $checks = $complianceRun->checks()
            ->with(['rule', 'asset'])
            ->when($request->filled('result'), fn($q) => $q->where('check_result', $request->result))
            ->orderBy('check_result')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Modules/Assets/Compliance/Runs/Show', [
            'run'    => $complianceRun,
            'checks' => $checks,
            'filters' => $request->only('result'),
        ]);
    }
}
