<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\ClaimApprovalStep;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use App\Modules\Finance\Claims\Models\ClaimPaymentProcessor;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\PolicyRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsSetupController extends Controller
{
    use ClaimsAuthTrait;

    // ── Setup index ───────────────────────────────────────────────────────────
    public function index(): Response
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $categories = ClaimCategory::withCount('claims')->orderBy('name')->get()->map(fn($c) => [
            'id'                 => $c->id,
            'name'               => $c->name,
            'code'               => $c->code,
            'description'        => $c->description,
            'requires_receipt'   => $c->requires_receipt,
            'max_amount'         => $c->max_amount ? (float) $c->max_amount : null,
            'approval_threshold' => $c->approval_threshold ? (float) $c->approval_threshold : null,
            'max_age_days'       => $c->max_age_days,
            'icon'               => $c->icon,
            'active'             => $c->active,
            'is_travel'          => $c->is_travel,
            'km_rate'            => $c->km_rate ? (float) $c->km_rate : null,
            'claims_count'       => $c->claims_count,
        ]);

        $policies = EventRuleMap::where('module', 'Claims')
            ->with(['rule.policy'])
            ->orderBy('action_pattern')
            ->get()
            ->map(fn($m) => [
                'id'               => $m->id,
                'action_pattern'   => $m->action_pattern,
                'active'           => $m->active,
                'rule_id'          => $m->rule_id,
                'rule_name'        => $m->rule?->name,
                'severity'         => $m->rule?->severity,
                'action_on_breach' => $m->rule?->action_on_breach,
                'policy_name'      => $m->rule?->policy?->name,
                'description'      => $m->rule?->description,
            ]);

        $availableRules = PolicyRule::with('policy')
            ->whereDoesntHave('eventMaps', fn($q) => $q->where('module', 'Claims'))
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'name'             => $r->name,
                'severity'         => $r->severity,
                'action_on_breach' => $r->action_on_breach,
                'policy_name'      => $r->policy?->name,
                'description'      => $r->description,
            ]);

        $approvalSteps = ClaimApprovalStep::leftJoin('users as u', 'u.id', '=', 'claim_approval_steps.employee_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->select(
                'claim_approval_steps.*',
                DB::raw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as employee_name")
            )
            ->orderBy('step_number')
            ->get()
            ->map(fn($s) => [
                'id'                => $s->id,
                'step_number'       => $s->step_number,
                'step_label'        => $s->step_label,
                'approver_type'     => $s->approver_type,
                'role_name'         => $s->role_name,
                'employee_id'       => $s->employee_id,
                'employee_name'     => trim($s->employee_name) ?: null,
                'amount_min'        => $s->amount_min !== null ? (float) $s->amount_min : null,
                'amount_max'        => $s->amount_max !== null ? (float) $s->amount_max : null,
                'applies_to_travel' => (bool) $s->applies_to_travel,
                'applies_to_standard' => (bool) $s->applies_to_standard,
                'is_active'         => (bool) $s->is_active,
            ]);

        $roles = DB::table('roles')->orderBy('name')->get(['id', 'name']);

        $users = DB::table('users as u')
            ->join('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['u.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'u.email']);

        $paymentProcessors = ClaimPaymentProcessor::leftJoin('users as u', 'u.id', '=', 'claim_payment_processors.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->select(
                'claim_payment_processors.*',
                DB::raw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as user_name"),
                'u.email as user_email'
            )
            ->orderBy('claim_payment_processors.created_at')
            ->get()
            ->map(fn ($pp) => [
                'id'             => $pp->id,
                'processor_type' => $pp->processor_type,
                'role_name'      => $pp->role_name,
                'user_id'        => $pp->user_id,
                'user_name'      => trim($pp->user_name) ?: null,
                'user_email'     => $pp->user_email,
                'is_active'      => (bool) $pp->is_active,
                'notes'          => $pp->notes,
            ]);

        return Inertia::render('Modules/Finance/Claims/Setup/Index', [
            'categories'        => $categories,
            'policies'          => $policies,
            'availableRules'    => $availableRules,
            'approvalSteps'     => $approvalSteps,
            'paymentProcessors' => $paymentProcessors,
            'roles'             => $roles,
            'users'             => $users,
        ]);
    }

    // ── Payment Processors CRUD ───────────────────────────────────────────────
    public function storePaymentProcessor(Request $request): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'processor_type' => 'required|in:role,user',
            'role_name'      => 'required_if:processor_type,role|nullable|string|max:80',
            'user_id'        => 'required_if:processor_type,user|nullable|exists:users,id',
            'notes'          => 'nullable|string|max:500',
        ]);

        ClaimPaymentProcessor::create(array_merge($data, [
            'is_active'  => true,
            'created_by' => Auth::id(),
        ]));

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties($data)
            ->event('payment_processor_added')
            ->log('Payment processor configuration added.');

        return back()->with('success', 'Payment processor added.');
    }

    public function destroyPaymentProcessor(ClaimPaymentProcessor $processor): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $processor->delete();

        activity('claims')
            ->causedBy(Auth::user())
            ->event('payment_processor_removed')
            ->log('Payment processor configuration removed.');

        return back()->with('success', 'Payment processor removed.');
    }

    // ── Categories CRUD ───────────────────────────────────────────────────────
    public function storeCategory(Request $request): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'code'               => 'required|string|max:40|unique:claim_categories,code',
            'description'        => 'nullable|string|max:500',
            'requires_receipt'   => 'boolean',
            'max_amount'         => 'nullable|numeric|min:0',
            'approval_threshold' => 'nullable|numeric|min:0',
            'max_age_days'       => 'nullable|integer|min:1|max:365',
            'icon'               => 'nullable|string|max:60',
            'active'             => 'boolean',
            'is_travel'          => 'boolean',
            'km_rate'            => 'nullable|numeric|min:0.01',
        ]);

        $category = ClaimCategory::create(array_merge($data, ['created_by' => Auth::id()]));

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($category)
            ->withProperties(['name' => $category->name, 'code' => $category->code])
            ->event('category_created')
            ->log("Claim category \"{$category->name}\" created.");

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, ClaimCategory $category): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'code'               => "required|string|max:40|unique:claim_categories,code,{$category->id}",
            'description'        => 'nullable|string|max:500',
            'requires_receipt'   => 'boolean',
            'max_amount'         => 'nullable|numeric|min:0',
            'approval_threshold' => 'nullable|numeric|min:0',
            'max_age_days'       => 'nullable|integer|min:1|max:365',
            'icon'               => 'nullable|string|max:60',
            'active'             => 'boolean',
            'is_travel'          => 'boolean',
            'km_rate'            => 'nullable|numeric|min:0.01',
        ]);

        $category->update($data);

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($category)
            ->withProperties(['name' => $category->name])
            ->event('category_updated')
            ->log("Claim category \"{$category->name}\" updated.");

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(ClaimCategory $category): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);
        abort_if($category->claims()->exists(), 422, 'Cannot delete a category that has claims.');

        $name = $category->name;
        $category->delete();

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties(['name' => $name])
            ->event('category_deleted')
            ->log("Claim category \"{$name}\" deleted.");

        return back()->with('success', 'Category deleted.');
    }

    // ── Approval Steps CRUD ───────────────────────────────────────────────────
    public function storeApprovalStep(Request $request): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'step_number'         => 'required|integer|min:1',
            'step_label'          => 'required|string|max:100',
            'approver_type'       => 'required|in:line_manager,role,employee',
            'role_name'           => 'nullable|string|max:80',
            'employee_id'         => 'nullable|exists:users,id',
            'amount_min'          => 'nullable|numeric|min:0',
            'amount_max'          => 'nullable|numeric|min:0',
            'applies_to_travel'   => 'boolean',
            'applies_to_standard' => 'boolean',
            'is_active'           => 'boolean',
        ]);

        $step = ClaimApprovalStep::create($data);

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($step)
            ->withProperties(['step_number' => $step->step_number, 'step_label' => $step->step_label, 'approver_type' => $step->approver_type])
            ->event('approval_step_created')
            ->log("Approval step {$step->step_number} \"{$step->step_label}\" created.");

        return back()->with('success', 'Approval step added.');
    }

    public function updateApprovalStep(Request $request, ClaimApprovalStep $step): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'step_number'         => 'required|integer|min:1',
            'step_label'          => 'required|string|max:100',
            'approver_type'       => 'required|in:line_manager,role,employee',
            'role_name'           => 'nullable|string|max:80',
            'employee_id'         => 'nullable|exists:users,id',
            'amount_min'          => 'nullable|numeric|min:0',
            'amount_max'          => 'nullable|numeric|min:0',
            'applies_to_travel'   => 'boolean',
            'applies_to_standard' => 'boolean',
            'is_active'           => 'boolean',
        ]);

        $step->update($data);

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($step)
            ->withProperties(['step_number' => $step->step_number, 'step_label' => $step->step_label])
            ->event('approval_step_updated')
            ->log("Approval step {$step->step_number} \"{$step->step_label}\" updated.");

        return back()->with('success', 'Approval step updated.');
    }

    public function destroyApprovalStep(ClaimApprovalStep $step): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $label = $step->step_label;
        $step->delete();

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties(['step_label' => $label])
            ->event('approval_step_deleted')
            ->log("Approval step \"{$label}\" deleted.");

        return back()->with('success', 'Approval step deleted.');
    }

    // ── Central Policy Rule Links ─────────────────────────────────────────────
    public function linkPolicyRule(Request $request): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'rule_id'        => 'required|exists:policy_rules,id',
            'action_pattern' => 'required|string|max:100',
        ]);

        EventRuleMap::create([
            'rule_id'        => $data['rule_id'],
            'module'         => 'Claims',
            'action_pattern' => $data['action_pattern'],
            'active'         => true,
        ]);

        $ruleName = PolicyRule::find($data['rule_id'])?->name ?? "Rule #{$data['rule_id']}";

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties(['rule' => $ruleName, 'action_pattern' => $data['action_pattern']])
            ->event('policy_rule_linked')
            ->log("Policy rule \"{$ruleName}\" linked to Claims event \"{$data['action_pattern']}\".");

        return back()->with('success', 'Policy rule linked to Claims.');
    }

    public function unlinkPolicyRule(int $id): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $mapping = EventRuleMap::findOrFail($id);
        abort_unless($mapping->module === 'Claims', 403);

        $pattern = $mapping->action_pattern;
        $mapping->delete();

        activity('claims')
            ->causedBy(Auth::user())
            ->withProperties(['action_pattern' => $pattern])
            ->event('policy_rule_unlinked')
            ->log("Policy rule unlinked from Claims event \"{$pattern}\".");

        return back()->with('success', 'Policy rule unlinked.');
    }
}
