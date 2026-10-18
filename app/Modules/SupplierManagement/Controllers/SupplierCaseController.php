<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\Blacklist;
use App\Modules\SupplierManagement\DueDiligence\Services\CaseWorkflow;
use App\Modules\SupplierManagement\DueDiligence\Services\EddPack;
use App\Modules\SupplierManagement\DueDiligence\Services\RoleMap;
use App\Modules\SupplierManagement\DueDiligence\Support\CaseState;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceEddTask;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierBlacklistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Working a due-diligence case: opening it, moving it, completing the enhanced pack, and barring a
 * supplier when it comes to that.
 *
 * The controller does no deciding of its own. Every rule about who may move what lives in
 * CaseWorkflow, so the same answer holds whether a move arrives from this screen, from a scheduled
 * job, or from another module — a controller that re-implemented the checks would be a second place
 * for them to differ.
 */
class SupplierCaseController extends Controller
{
    public function __construct(
        private readonly CaseWorkflow $workflow,
        private readonly EddPack $edd,
        private readonly Blacklist $blacklist,
    ) {}

    public function open(Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $case = $this->workflow->open($supplier);

        return back()->with('success', __('supplier_management.cases.opened', ['reference' => $case->reference]));
    }

    /** Make a move. */
    public function transition(Request $request, Supplier $supplier, DueDiligenceCase $case): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $case->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(CaseState::transitions()))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['string', 'max:500'],
            'conditions_due' => ['nullable', 'date', 'after:today'],
            // Overriding a failing gate is deliberate and recorded; it is never a default.
            'override' => ['nullable', 'boolean'],
        ]);

        $case = $this->workflow->apply($case, $data['action'], $data);

        // Entering enhanced due diligence builds the pack from what triggered it, rather than
        // handing over a fixed checklist somebody has to read past.
        if ($case->state === CaseState::ENHANCED_DUE_DILIGENCE) {
            $this->edd->generate($case);
        }

        return back()->with('success', __('supplier_management.cases.moved', [
            'state' => $case->stateLabel(),
        ]));
    }

    /** Record what came back for one enhanced due-diligence item. */
    public function settleEdd(Request $request, Supplier $supplier, DueDiligenceEddTask $task): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $task->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                DueDiligenceEddTask::STATUS_PROVIDED,
                DueDiligenceEddTask::STATUS_WAIVED,
                DueDiligenceEddTask::STATUS_FAILED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->edd->settle($task, $data['status'], $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['note' => __('supplier_management.cases.errors.waiver_reason')]);
        }

        return back()->with('success', __('supplier_management.cases.edd_recorded'));
    }

    /**
     * Bar an entity or a person.
     *
     * Separate from the case transition on purpose: a blacklist entry outlives the case, and the
     * useful one is written about a party that is not a supplier yet.
     */
    public function blacklist(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate([
            'scope' => ['required', Rule::in([SupplierBlacklistEntry::SCOPE_ENTITY, SupplierBlacklistEntry::SCOPE_PERSON])],
            'entity_name' => ['required', 'string', 'max:250'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'person_id' => ['nullable', 'integer'],
            'identifier' => ['nullable', 'string', 'max:60'],
            'reason' => ['required', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::in(['permanent', 'fixed_term'])],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'review_on' => ['nullable', 'date', 'after:today'],
        ]);

        if (! app(RoleMap::class)->can(Auth::user(), RoleSlot::EXECUTIVE)) {
            return back()->withErrors(['reason' => __('supplier_management.cases.errors.blacklist_slot')]);
        }

        $this->blacklist->add($data + [
            'supplier_id' => $data['scope'] === SupplierBlacklistEntry::SCOPE_ENTITY ? $supplier->id : null,
            'decided_role' => RoleSlot::label(RoleSlot::EXECUTIVE),
        ]);

        // The case follows the bar where there is one open.
        if ($case = DueDiligenceCase::openFor($supplier->id)) {
            $check = $this->workflow->check($case, 'blacklist');

            if ($check['allowed']) {
                $this->workflow->apply($case, 'blacklist', ['reason' => $data['reason']]);
            }
        }

        return back()->with('success', __('supplier_management.cases.blacklisted'));
    }

    public function liftBlacklist(Request $request, SupplierBlacklistEntry $entry): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if (! app(RoleMap::class)->can(Auth::user(), RoleSlot::EXECUTIVE)) {
            return back()->withErrors(['reason' => __('supplier_management.cases.errors.blacklist_slot')]);
        }

        $entry->lift(Auth::id(), $data['reason']);

        return back()->with('success', __('supplier_management.cases.blacklist_lifted'));
    }

    /** Map the four sign-off slots to this tenant's own people. */
    public function saveRoles(Request $request): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate([
            'map' => ['required', 'array'],
            'map.*.users' => ['nullable', 'array'],
            'map.*.users.*' => ['integer'],
            'map.*.roles' => ['nullable', 'array'],
            'map.*.roles.*' => ['string', 'max:120'],
            'case_sla_days' => ['nullable', 'integer', 'min:1', 'max:180'],
        ]);

        app(RoleMap::class)->save($data['map']);

        if (isset($data['case_sla_days'])) {
            \App\Modules\SupplierManagement\Models\SupplierSetting::query()
                ->firstOrCreate([])->update(['case_sla_days' => $data['case_sla_days']]);
        }

        return back()->with('success', __('supplier_management.cases.roles_saved'));
    }
}
