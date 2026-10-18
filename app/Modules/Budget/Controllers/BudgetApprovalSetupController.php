<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Budget\Models\BudgetApprovalRequest;
use App\Modules\Budget\Models\BudgetApprovalStep;
use App\Modules\Budget\Models\BudgetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approval designer — who signs off what, per company.
 *
 * This is the page the whole feature exists for. Budget approval used to be a coded rule: one
 * button, one permission, one signature. Companies do not agree on that and never have, so the
 * shape is designed here instead: a ladder per kind of decision, optionally narrowed to capital or
 * to one category, optionally skipped below a threshold.
 *
 * The page also states what happens with *no* flow designed, because that is the state most tenants
 * will be in and a default that is never written down is one people are surprised by.
 */
class BudgetApprovalSetupController extends Controller
{
    public function index(): Response
    {
        $this->authorize('update', Budget::class);

        return Inertia::render('Modules/Budget/Settings/Approvals', [
            'flows' => BudgetApprovalFlow::with('steps')->orderBy('subject_type')->orderBy('name')->get()
                ->map(fn (BudgetApprovalFlow $f) => [
                    'id'               => $f->uuid,
                    'name'             => $f->name,
                    'subject_type'     => $f->subject_type,
                    'is_active'        => (bool) $f->is_active,
                    'threshold'        => $f->threshold_amount === null ? null : (float) $f->threshold_amount,
                    'currency'         => $f->currency,
                    'expenditure_type' => $f->expenditure_type,
                    'category_id'      => $f->budget_category_id,
                    'escalate_after_hours' => $f->escalate_after_hours,
                    'description'      => $f->description,
                    'pending'          => BudgetApprovalRequest::pending()->where('flow_id', $f->id)->count(),
                    'steps'            => $f->steps->map(fn (BudgetApprovalStep $s) => [
                        'id'            => $s->uuid,
                        'position'      => $s->position,
                        'name'          => $s->name,
                        'approver_type' => $s->approver_type,
                        'approver_ref'  => $s->approver_ref,
                        'min_approvals' => $s->min_approvals,
                        'allow_self'    => (bool) $s->allow_self_approval,
                    ])->values(),
                ])->values(),
            'subjectTypes'  => BudgetApprovalFlow::SUBJECT_TYPES,
            'approverTypes' => BudgetApprovalStep::APPROVER_TYPES,
            'roles'         => $this->roles(),
            'users'         => $this->users(),
            'categories'    => BudgetCategory::orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->all(),
            // A step configured as "the submitter's manager" on a tenant with no HR is a step
            // nobody can satisfy. The page says so rather than the engine failing quietly later.
            'hasHr'         => Schema::hasTable('hr_people'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $this->rules($request);

        $flow = BudgetApprovalFlow::create([
            'name'                 => $data['name'],
            'subject_type'         => $data['subject_type'],
            'is_active'            => $data['is_active'] ?? true,
            'threshold_amount'     => $data['threshold'] ?? null,
            'currency'             => $data['currency'] ?? null,
            'expenditure_type'     => $data['expenditure_type'] ?? null,
            'budget_category_id'   => $data['category_id'] ?? null,
            'escalate_after_hours' => $data['escalate_after_hours'] ?? null,
            'description'          => $data['description'] ?? null,
            'created_by'           => Auth::id(),
        ]);

        $this->syncSteps($flow, $data['steps'] ?? []);

        return back()->with('success', __('budget_management.flash.flow_saved'));
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $flow = BudgetApprovalFlow::where('uuid', $uuid)->firstOrFail();
        $data = $this->rules($request);

        $flow->update([
            'name'                 => $data['name'],
            'subject_type'         => $data['subject_type'],
            'is_active'            => $data['is_active'] ?? true,
            'threshold_amount'     => $data['threshold'] ?? null,
            'currency'             => $data['currency'] ?? null,
            'expenditure_type'     => $data['expenditure_type'] ?? null,
            'budget_category_id'   => $data['category_id'] ?? null,
            'escalate_after_hours' => $data['escalate_after_hours'] ?? null,
            'description'          => $data['description'] ?? null,
        ]);

        $this->syncSteps($flow, $data['steps'] ?? []);

        return back()->with('success', __('budget_management.flash.flow_saved'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $flow = BudgetApprovalFlow::where('uuid', $uuid)->firstOrFail();

        // Requests already in the queue keep pointing at a soft-deleted flow, which is what lets
        // their trail still read after somebody retires the routing that produced them.
        $flow->delete();

        return back()->with('success', __('budget_management.flash.flow_deleted'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function rules(Request $request): array
    {
        return $request->validate([
            'name'                 => ['required', 'string', 'max:120'],
            'subject_type'         => ['required', Rule::in(BudgetApprovalFlow::SUBJECT_TYPES)],
            'is_active'            => ['nullable', 'boolean'],
            'threshold'            => ['nullable', 'numeric', 'min:0'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'expenditure_type'     => ['nullable', 'in:capex,opex'],
            'category_id'          => ['nullable', 'integer', 'exists:budget_categories,id'],
            'escalate_after_hours' => ['nullable', 'integer', 'between:1,8760'],
            'description'          => ['nullable', 'string', 'max:1000'],

            // A flow needs at least one rung; an empty ladder approves nothing and blocks
            // everything, which is worse than having no flow at all.
            'steps'                 => ['required', 'array', 'min:1'],
            'steps.*.name'          => ['required', 'string', 'max:120'],
            'steps.*.approver_type' => ['required', Rule::in(BudgetApprovalStep::APPROVER_TYPES)],
            'steps.*.approver_ref'  => ['nullable', 'string', 'max:120'],
            'steps.*.min_approvals' => ['nullable', 'integer', 'between:1,10'],
            'steps.*.allow_self'    => ['nullable', 'boolean'],
        ]);
    }

    private function syncSteps(BudgetApprovalFlow $flow, array $steps): void
    {
        DB::transaction(function () use ($flow, $steps) {
            // Replaced wholesale rather than diffed. Steps carry no history of their own — the
            // decisions do, on the actions table — so rewriting them loses nothing and avoids a
            // reorder turning into a tangle of position updates.
            $flow->steps()->delete();

            foreach (array_values($steps) as $index => $step) {
                BudgetApprovalStep::create([
                    'flow_id'             => $flow->id,
                    'position'            => $index + 1,
                    'name'                => $step['name'],
                    'approver_type'       => $step['approver_type'],
                    'approver_ref'        => $step['approver_ref'] ?? null,
                    'min_approvals'       => $step['min_approvals'] ?? 1,
                    'allow_self_approval' => (bool) ($step['allow_self'] ?? false),
                ]);
            }
        });
    }

    /** @return array<int, string> */
    private function roles(): array
    {
        return DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
    }

    /**
     * People a step may name, with the name a person would recognise.
     *
     * Picked from a list, never typed as a primary key — nobody should have to look up an id in a
     * database to configure an approval, and getting it wrong silently is worse than not offering it.
     *
     * @return array<int, array{id:int,label:string}>
     */
    private function users(): array
    {
        $userClass = config('auth.providers.users.model');

        return $userClass::query()
            ->with('person:id,user_id,first_name,surname')
            ->limit(500)
            ->get()
            ->map(function ($user) {
                $person = $user->person;
                $name = $person
                    ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                    : '';

                return ['id' => (int) $user->id, 'label' => $name !== '' ? $name : (string) $user->email];
            })
            ->sortBy('label')
            ->values()
            ->all();
    }
}
