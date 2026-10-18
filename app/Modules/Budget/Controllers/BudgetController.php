<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCategory;
use App\Modules\Budget\Models\BudgetOwner;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetPlanningService;
use App\Modules\Budget\Services\BudgetRollupService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Budget\Services\BudgetTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetApprovalService $approvals,
        private readonly BudgetTrackingService $tracking,
        private readonly BudgetPlanningService $planning,
        private readonly BudgetRollupService $rollup,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $list = Budget::query()
            ->with(['financialYear:id,name', 'category:id,name'])
            ->filter($request->only('search', 'status', 'financial_year_id', 'expenditure_type', 'scope_type'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // One aggregate for the page rather than four per row: a twenty-row list used to fire
        // eighty-one queries, and a tenant with real data felt it.
        $positions = $this->budgets->amountsForAll($list->getCollection()->pluck('id')->all());

        $list->through(function (Budget $b) use ($positions) {
            $a = $positions[$b->id] ?? $this->budgets->amounts($b);

            return [
                'id' => $b->id, 'uuid' => $b->uuid, 'code' => $b->code, 'name' => $b->name,
                'financial_year' => $b->financialYear?->name, 'category' => $b->category?->name,
                'expenditure_type' => $b->expenditure_type, 'scope_type' => $b->scope_type,
                'scope_label' => $b->scope_label, 'currency' => $b->currency, 'status' => $b->status,
                'allocated' => $a['allocated'], 'available' => $a['available'],
                'reserved' => $a['reserved'], 'committed' => $a['committed'], 'actual' => $a['actual'],
            ];
        });

        return Inertia::render('Modules/Budget/Index', [
            'budgets'        => $list,
            'filters'        => $request->only('search', 'status', 'financial_year_id', 'expenditure_type', 'scope_type'),
            'financialYears' => FinancialYear::orderByDesc('is_current')->orderByDesc('start_date')->get(['id', 'name']),
            'statuses'       => ['draft', 'pending', 'approved', 'active', 'closed', 'rejected'],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Budget::class);

        return Inertia::render('Modules/Budget/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'financial_year_id' => ['nullable', 'integer', 'exists:financial_years,id'],
            'budget_period_id'  => ['nullable', 'integer'],
            'budget_category_id' => ['nullable', 'integer'],
            'expenditure_type'  => ['required', 'in:capex,opex'],
            'scope_type'        => ['required', 'in:department,project,category,general'],
            'scope_label'       => ['nullable', 'string', 'max:255'],
            'currency'          => ['nullable', 'string', 'max:3'],
            'allocated_amount'  => ['required', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string'],
        ]);

        $budget = $this->budgets->createBudget($data, Auth::id());

        return redirect()->route('budgets.show', $budget->id)->with('success', __('budget_management.flash.budget_created'));
    }

    public function show(Budget $budget): Response
    {
        $this->authorize('view', Budget::class);

        $budget->load([
            // The whole year, not just its name: the forecast measures elapsed time against its
            // start and end dates, and a narrowed select would silently make every forecast blank.
            'financialYear', 'period:id,name', 'category:id,name',
            'allocations', 'adjustments', 'overrides',
            'owners',
            'reservations' => fn ($q) => $q->latest()->limit(50),
            'commitments' => fn ($q) => $q->latest()->limit(50),
            'consumptions' => fn ($q) => $q->latest()->limit(50),
        ]);

        $amounts = $this->budgets->amounts($budget);
        $pending = $this->approvals->pendingFor('budget', $budget->id);
        $phasing = $this->planning->phasing($budget);

        return Inertia::render('Modules/Budget/Show', [
            'budget'   => $budget,
            'amounts'  => $amounts + $this->tracking->percentages($amounts),
            'statuses' => ['draft', 'pending', 'approved', 'active', 'closed', 'rejected'],

            // When the money is planned to go, beside where it has actually gone. Empty when the
            // year has no periods defined — the page says so rather than drawing an empty chart.
            'phasing'  => $phasing,
            // Where the run rate lands by year end (arithmetic), and where the owner now expects it
            // to land (judgement). A budget at 60% in month four is not fine.
            'forecast' => $this->planning->forecast($budget, $amounts) + $this->planning->reforecast($budget, $phasing, $amounts),
            'varianceThreshold' => (float) ($budget->financialYear?->variance_threshold_pct ?? \App\Modules\Budget\Models\FinancialYear::DEFAULT_VARIANCE_THRESHOLD_PCT),
            // What the allocation has been, each time it moved, and why.
            'revisions' => $budget->revisions()->latest('version')->limit(25)->get()
                ->map(fn ($r) => [
                    'version'  => $r->version,
                    'previous' => $r->previous_amount === null ? null : (float) $r->previous_amount,
                    'amount'   => (float) $r->amount,
                    'type'     => $r->reason_type,
                    'reason'   => $r->reason,
                    'at'       => $r->created_at?->toDateTimeString(),
                ])->all(),

            // Why a purchase order against this budget would bounce, in the words the encumbrance
            // check itself uses — so the page and the refusal cannot say different things.
            'blocked'  => $this->tracking->blockReason($budget, $amounts),
            'breaches' => $this->tracking->breaches($budget, $amounts)
                ->map(fn ($rule) => ['name' => $rule->name, 'severity' => $rule->severity])->all(),

            'approvals' => [
                // What is currently in flight for this budget, whatever kind of thing it is about.
                'pending' => $budget->approvalRequests()->pending()->get()
                    ->map(fn ($r) => [
                        'id'       => $r->uuid,
                        'subject'  => $r->subject_type,
                        'subject_id' => $r->subject_id,
                        'label'    => $r->subject_label,
                        'amount'   => $r->amount === null ? null : (float) $r->amount,
                        'step'     => $r->currentStep()?->name,
                        'position' => $r->current_position,
                        'steps'    => $r->flow?->steps->count() ?? 1,
                        'waiting'  => $r->waitingHours(),
                    ])->all(),
                // The trail: every decision anybody has made about this budget, newest first.
                'trail' => $budget->approvalRequests()->with('actions')->limit(25)->get()
                    ->flatMap(fn ($r) => $r->actions->map(fn ($a) => [
                        'subject'  => $r->subject_type,
                        'label'    => $r->subject_label,
                        'actor'    => $a->actor_name,
                        'decision' => $a->decision,
                        'comment'  => $a->comment,
                        'at'       => $a->acted_at?->toDateTimeString(),
                    ]))->sortByDesc('at')->take(40)->values()->all(),
                'budget_request' => $pending?->uuid,
            ],

            // Who answers for this budget. Owners are who alerts reach and who a "budget owner"
            // approval step resolves to, so the list is editable here rather than being a
            // side-effect of whoever happened to create the record.
            'owners' => $budget->owners->map(fn (BudgetOwner $o) => [
                'id'      => $o->id,
                'user_id' => $o->user_id,
                'role'    => $o->role,
                'name'    => $this->userLabels()[$o->user_id] ?? "#{$o->user_id}",
            ])->all(),
            'people' => collect($this->userLabels())->map(fn ($label, $id) => ['id' => $id, 'label' => $label])
                ->sortBy('label')->values()->all(),

            // Where this sits in the tree, and what it would consolidate to. A division head needs
            // the second number; a cost-centre owner needs to know they are inside one.
            'rollup' => [
                'parent_id'    => $budget->parent_id,
                // The roll-up links to each child, and that link keys on the uuid.
                'children'     => $budget->children()->orderBy('code')->get(['id', 'uuid', 'code', 'name'])
                    ->map(fn (Budget $c) => ['id' => $c->id, 'uuid' => $c->uuid, 'label' => "{$c->code} · {$c->name}"])->all(),
                'consolidated' => $budget->children()->exists() ? $this->rollup->consolidated($budget) : null,
                'options'      => Budget::query()
                    ->whereKeyNot($budget->id)
                    ->when($budget->financial_year_id, fn ($q, $id) => $q->where('financial_year_id', $id))
                    ->orderBy('code')->limit(500)->get(['id', 'code', 'name'])
                    ->map(fn (Budget $b) => ['id' => $b->id, 'uuid' => $b->uuid, 'label' => "{$b->code} · {$b->name}"])->all(),
            ],

            'can' => [
                'update'  => request()->user()->can('update', Budget::class),
                'approve' => request()->user()->can('approve', Budget::class),
            ],
        ]);
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        // NOTE: allocated_amount is deliberately NOT editable here. Changing the
        // allocation must go through a BudgetAdjustment (increase/decrease/transfer)
        // so it's governed by the approval workflow and the encumbrance ledger — a
        // direct PATCH would bypass that entirely.
        $data = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:255'],
            'budget_category_id' => ['nullable', 'integer', 'exists:budget_categories,id'],
            'expenditure_type' => ['nullable', 'in:capex,opex'],
            'scope_type'       => ['nullable', 'in:department,project,category,general'],
            'scope_label'      => ['nullable', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
        ]);

        $budget->update($data);

        return back()->with('success', __('budget_management.flash.budget_updated'));
    }

    /**
     * Send a draft budget for approval.
     *
     * This replaces the Approve button that used to sit on this page. The difference is not
     * cosmetic: whoever holds `budgets.approve` could previously activate an allocation alone, on
     * the same screen they had just written it, with no record of who else should have seen it.
     * Now it enters a queue somebody else works — and if the tenant has designed no flow, that
     * queue is exactly the same set of people, which is the point of the default.
     */
    public function submit(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        abort_unless(in_array($budget->status, ['draft', 'rejected'], true), 422, __('budget_management.flash.not_submittable'));

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $approval = $this->approvals->submit('budget', $budget, $budget, [
            'label'    => "{$budget->code} · {$budget->name}",
            'amount'   => (float) $budget->allocated_amount,
            'currency' => $budget->currency,
            'note'     => $data['note'] ?? null,
        ]);

        // Below every threshold anybody set: approved on submission, which is what a threshold is
        // for. The state it lands in is identical to one that climbed a ladder.
        if ($approval === null) {
            $this->budgets->approveBudget($budget, Auth::id(), __('budget_management.approvals.auto_below_threshold'));

            return back()->with('success', __('budget_management.flash.budget_activated'));
        }

        $budget->update(['status' => 'pending']);

        return back()->with('success', __('budget_management.flash.budget_submitted'));
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $this->authorize('delete', Budget::class);
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', __('budget_management.flash.budget_deleted'));
    }

    /**
     * Put this budget under another, or take it out of the tree.
     *
     * The refusals live in the service — a loop, a parent in a different financial year — because
     * they are rules about the shape of a budget structure, not about this HTTP request.
     */
    public function setParent(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate(['parent_id' => ['nullable', 'integer', 'exists:budgets,id']]);

        try {
            $this->rollup->setParent($budget, $data['parent_id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['parent_id' => $e->getMessage()]);
        }

        return back()->with('success', __('budget_management.flash.parent_saved'));
    }

    // ── Owners and delegates ─────────────────────────────────────────────────

    /**
     * Who answers for this budget.
     *
     * Not decoration: owners are who alert notifications reach, and a step configured as
     * "the budget owner" resolves against this list. A budget with nobody on it is one whose
     * warnings go to whoever happens to hold the approval grant.
     */
    public function ownerStore(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role'    => ['nullable', 'in:owner,delegate'],
        ]);

        BudgetOwner::updateOrCreate(
            ['budget_id' => $budget->id, 'user_id' => $data['user_id']],
            ['role' => $data['role'] ?? 'owner'],
        );

        return back()->with('success', __('budget_management.flash.owner_added'));
    }

    public function ownerDestroy(Budget $budget, BudgetOwner $owner): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        abort_unless((int) $owner->budget_id === (int) $budget->id, 404);

        $owner->delete();

        return back()->with('success', __('budget_management.flash.owner_removed'));
    }

    /**
     * User id => the name a colleague would recognise, resolved once per request.
     *
     * There is no users.name column on this platform; the person's name lives in HR, and an owner
     * list that reads "#41" is one nobody can check.
     *
     * @return array<int, string>
     */
    private function userLabels(): array
    {
        static $labels = null;

        if ($labels !== null) {
            return $labels;
        }

        $userClass = config('auth.providers.users.model');

        return $labels = $userClass::query()
            ->with('person:id,user_id,first_name,surname')
            ->limit(500)
            ->get()
            ->mapWithKeys(function ($user) {
                $person = $user->person;
                $name = $person ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) : '';

                return [(int) $user->id => $name !== '' ? $name : (string) $user->email];
            })
            ->all();
    }

    private function formOptions(): array
    {
        return [
            'financialYears' => FinancialYear::orderByDesc('is_current')->orderByDesc('start_date')->get(['id', 'name']),
            'categories'     => BudgetCategory::where('status', true)->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }
}
