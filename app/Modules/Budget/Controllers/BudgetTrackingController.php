<?php

namespace App\Modules\Budget\Controllers;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAlert;
use App\Modules\Budget\Models\BudgetAlertRule;
use App\Modules\Budget\Models\BudgetCategory;
use App\Modules\Budget\Services\BudgetPlanningService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Budget\Services\BudgetTrackingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Budget tracking: where every budget stands, what has tripped, and what is stopped.
 *
 * Three things on one page because they are the same question asked at three levels of urgency —
 * how are we doing, what should somebody look at, and what has already been refused. Splitting them
 * across three screens is how a company ends up watching the first and never opening the third.
 */
class BudgetTrackingController extends Controller
{
    public function __construct(
        private readonly BudgetTrackingService $tracking,
        private readonly BudgetService $budgets,
        private readonly BudgetPlanningService $planning,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $budgets = Budget::query()
            ->with(['category:id,name', 'financialYear:id,name'])
            ->when($request->query('financial_year_id'), fn ($q, $id) => $q->where('financial_year_id', $id))
            ->orderBy('code')
            ->get();

        $positions = $this->tracking->positions($budgets->pluck('id')->all());
        $rules = $this->tracking->rules();

        $tracked = $budgets->map(function (Budget $budget) use ($positions, $rules) {
            $amounts = $positions[$budget->id] ?? $this->budgets->amounts($budget);

            $breaches = $rules
                ->filter(fn (BudgetAlertRule $r) => $r->applies($budget) && $r->breached($amounts))
                ->sortByDesc(fn (BudgetAlertRule $r) => $r->rank())
                ->values();

            return [
                'id'         => $budget->id,
                // The tracking table links each row to its budget, and that link keys on the uuid.
                'uuid'       => $budget->uuid,
                'code'       => $budget->code,
                'name'       => $budget->name,
                'category'   => $budget->category?->name,
                'type'       => $budget->expenditure_type,
                'status'     => $budget->status,
                'currency'   => $budget->currency,
                'allocated'  => $amounts['allocated'],
                'reserved'   => $amounts['reserved'],
                'committed'  => $amounts['committed'],
                'actual'     => $amounts['actual'],
                'available'  => $amounts['available'],
                'used_pct'   => $amounts['committed_pct'] ?? 0,
                'spent_pct'  => $amounts['actual_pct'] ?? 0,
                'severity'   => $breaches->first()?->severity,
                'breaches'   => $breaches->pluck('name')->all(),
                // The one sentence that says why a purchase order will bounce, computed the same
                // way the encumbrance check computes it.
                'blocked'    => $this->tracking->blockReason($budget, $amounts),
            ];
        })->values();

        return Inertia::render('Modules/Budget/Tracking/Index', [
            'budgets' => $tracked,
            // Closed periods that missed their plan by more than the year's threshold and still
            // have no reason recorded. Each one is a question somebody will ask at month end.
            'unexplained' => $this->planning
                ->materialVariances($request->query('financial_year_id') ? (int) $request->query('financial_year_id') : null, null, true)
                ->map(fn ($v) => [
                    'budget_uuid' => $v['budget_uuid'], 'code' => $v['code'], 'name' => $v['name'],
                    'period' => $v['label'], 'planned' => $v['planned'], 'actual' => $v['actual'],
                    'variance' => $v['variance'], 'variance_pct' => $v['variance_pct'],
                ])->all(),
            'alerts'  => BudgetAlert::live()
                ->with(['budget:id,code,name,currency', 'rule:id,name'])
                ->orderByRaw("case severity when 'block' then 1 when 'critical' then 2 when 'warning' then 3 else 4 end")
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (BudgetAlert $a) => [
                    'id'         => $a->uuid,
                    'budget'     => $a->budget ? ['id' => $a->budget->id, 'code' => $a->budget->code, 'name' => $a->budget->name] : null,
                    'rule'       => $a->rule?->name,
                    'severity'   => $a->severity,
                    'metric'     => $a->metric,
                    'threshold'  => $a->threshold === null ? null : (float) $a->threshold,
                    'observed'   => $a->observed === null ? null : (float) $a->observed,
                    'message'    => $a->message,
                    'status'     => $a->status,
                    'raised_at'  => $a->created_at?->toDateTimeString(),
                    'seen_at'    => $a->last_seen_at?->toDateTimeString(),
                ])->all(),
            'rules' => $this->tracking->rules()->map(fn (BudgetAlertRule $r) => [
                'id'            => $r->uuid,
                'name'          => $r->name,
                'scope_type'    => $r->scope_type,
                'scope_value'   => $r->scope_value,
                'scope_id'      => $r->scope_id,
                'metric'        => $r->metric,
                'threshold'     => (float) $r->threshold,
                'severity'      => $r->severity,
                'notify_owners' => (bool) $r->notify_owners,
                'notify_roles'  => $r->notify_roles ?? [],
                'is_active'     => (bool) $r->is_active,
                'description'   => $r->description,
            ])->all(),
            'options' => [
                'scopeTypes' => BudgetAlertRule::SCOPE_TYPES,
                'metrics'    => BudgetAlertRule::METRICS,
                'severities' => BudgetAlertRule::SEVERITIES,
                'categories' => BudgetCategory::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->all(),
                'budgets'    => $budgets->map(fn (Budget $b) => ['id' => $b->id, 'uuid' => $b->uuid, 'label' => "{$b->code} · {$b->name}"])->all(),
                'roles'      => \Illuminate\Support\Facades\DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            ],
            'canManage' => $request->user()->can('update', Budget::class),
        ]);
    }

    // ── Alert rules ──────────────────────────────────────────────────────────

    public function ruleStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        BudgetAlertRule::create($this->ruleRules($request) + ['created_by' => Auth::id()]);
        $this->tracking->forgetRules();

        return back()->with('success', __('budget_management.flash.rule_saved'));
    }

    public function ruleUpdate(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        BudgetAlertRule::where('uuid', $uuid)->firstOrFail()->update($this->ruleRules($request));
        $this->tracking->forgetRules();

        return back()->with('success', __('budget_management.flash.rule_saved'));
    }

    public function ruleDestroy(string $uuid): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        BudgetAlertRule::where('uuid', $uuid)->firstOrFail()->delete();
        $this->tracking->forgetRules();

        return back()->with('success', __('budget_management.flash.rule_deleted'));
    }

    // ── Alerts ───────────────────────────────────────────────────────────────

    /** Somebody has seen it and is dealing with it. Not the same as fixed — the sweep decides that. */
    public function acknowledge(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('viewAny', Budget::class);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->tracking->acknowledge(
            BudgetAlert::where('uuid', $uuid)->firstOrFail(),
            Auth::id(),
            $data['note'] ?? null,
        );

        return back()->with('success', __('budget_management.flash.alert_acknowledged'));
    }

    /**
     * Re-evaluate everything now rather than waiting for tonight.
     *
     * Useful the moment somebody has just written their first rule and wants to see what it catches
     * — a governance feature whose effect is invisible until the next morning is one people assume
     * is broken.
     */
    public function sweep(): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $result = $this->tracking->sweep();

        return back()->with('success', __('budget_management.flash.sweep_done', [
            'raised'   => $result['raised'],
            'resolved' => $result['resolved'],
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function ruleRules(Request $request): array
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'scope_type'    => ['required', Rule::in(BudgetAlertRule::SCOPE_TYPES)],
            'scope_value'   => ['nullable', 'required_if:scope_type,expenditure_type', 'in:capex,opex'],
            'scope_id'      => ['nullable', 'required_if:scope_type,category', 'required_if:scope_type,budget', 'integer'],
            'metric'        => ['required', Rule::in(BudgetAlertRule::METRICS)],
            'threshold'     => ['required', 'numeric'],
            'severity'      => ['required', Rule::in(BudgetAlertRule::SEVERITIES)],
            'notify_owners' => ['nullable', 'boolean'],
            'notify_roles'  => ['nullable', 'array'],
            'notify_roles.*' => ['string', 'max:120'],
            'is_active'     => ['nullable', 'boolean'],
            'description'   => ['nullable', 'string', 'max:1000'],
        ]);

        // A percentage threshold above 100 is almost always a typo for an amount, and one below
        // zero can never trip. Neither is worth storing as a rule somebody will later wonder about.
        if (in_array($data['metric'], ['committed_pct', 'actual_pct'], true)) {
            $request->validate(['threshold' => ['numeric', 'between:1,200']]);
        }

        // Only the scoping the chosen scope_type actually uses is kept, so a rule edited from
        // "category" back to "all" does not carry a stale category id nobody can see.
        return [
            'name'          => $data['name'],
            'scope_type'    => $data['scope_type'],
            'scope_value'   => $data['scope_type'] === 'expenditure_type' ? $data['scope_value'] : null,
            'scope_id'      => in_array($data['scope_type'], ['category', 'budget'], true) ? $data['scope_id'] : null,
            'metric'        => $data['metric'],
            'threshold'     => $data['threshold'],
            'severity'      => $data['severity'],
            'notify_owners' => $data['notify_owners'] ?? true,
            'notify_roles'  => $data['notify_roles'] ?? null,
            'is_active'     => $data['is_active'] ?? true,
            'description'   => $data['description'] ?? null,
        ];
    }
}
