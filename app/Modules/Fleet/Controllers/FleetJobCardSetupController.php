<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetJobCardApprovalStep;
use App\Modules\Fleet\Models\FleetJobCardCatalogPart;
use App\Modules\Fleet\Models\FleetJobCardPartCategory;
use App\Modules\Fleet\Models\FleetJobCardServiceType;
use App\Modules\Fleet\Models\FleetJobCardSetting;
use App\Core\User\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job card configuration: the service-type and parts catalogues, the approval
 * process flow, and the module's own settings. One screen, four tabs.
 */
class FleetJobCardSetupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/Setup', [
            'serviceTypes'   => FleetJobCardServiceType::orderBy('name')->get(),
            'partCategories' => FleetJobCardPartCategory::withCount('parts')->orderBy('name')->get(),
            'catalogParts'   => FleetJobCardCatalogPart::with('category:id,name')->orderBy('name')->paginate(25),
            'approvalSteps'  => FleetJobCardApprovalStep::with('approver:id,email')->orderBy('sequence')->get(),
            'settings'       => FleetJobCardSetting::current(),
            'approvers'      => User::query()->orderBy('email')->limit(500)->get(['id', 'email']),
        ]);
    }

    // ── Service types ─────────────────────────────────────────────────────────

    public function storeServiceType(Request $request): RedirectResponse
    {
        $data = $this->validateServiceType($request);
        FleetJobCardServiceType::create($data + ['created_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.service_type_saved'));
    }

    public function updateServiceType(Request $request, FleetJobCardServiceType $serviceType): RedirectResponse
    {
        $serviceType->update($this->validateServiceType($request) + ['updated_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.service_type_saved'));
    }

    /** Soft-delete keeps historical job card lines readable. */
    public function destroyServiceType(FleetJobCardServiceType $serviceType): RedirectResponse
    {
        $serviceType->delete();

        return back()->with('success', __('fleet_job_cards.setup.service_type_deleted'));
    }

    public function toggleServiceType(FleetJobCardServiceType $serviceType): RedirectResponse
    {
        $serviceType->update(['is_active' => ! $serviceType->is_active]);

        return back()->with('success', __('fleet_job_cards.setup.status_changed'));
    }

    // ── Parts categories ──────────────────────────────────────────────────────

    public function storeCategory(Request $request): RedirectResponse
    {
        FleetJobCardPartCategory::create($this->validateCategory($request) + ['created_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.category_saved'));
    }

    public function updateCategory(Request $request, FleetJobCardPartCategory $category): RedirectResponse
    {
        $category->update($this->validateCategory($request) + ['updated_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.category_saved'));
    }

    public function destroyCategory(FleetJobCardPartCategory $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', __('fleet_job_cards.setup.category_deleted'));
    }

    public function toggleCategory(FleetJobCardPartCategory $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', __('fleet_job_cards.setup.status_changed'));
    }

    // ── Catalogue parts ───────────────────────────────────────────────────────

    public function storePart(Request $request): RedirectResponse
    {
        FleetJobCardCatalogPart::create($this->validatePart($request) + ['created_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.part_saved'));
    }

    public function updatePart(Request $request, FleetJobCardCatalogPart $part): RedirectResponse
    {
        $part->update($this->validatePart($request) + ['updated_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.part_saved'));
    }

    public function destroyPart(FleetJobCardCatalogPart $part): RedirectResponse
    {
        $part->delete();

        return back()->with('success', __('fleet_job_cards.setup.part_deleted'));
    }

    public function togglePart(FleetJobCardCatalogPart $part): RedirectResponse
    {
        $part->update(['is_active' => ! $part->is_active]);

        return back()->with('success', __('fleet_job_cards.setup.status_changed'));
    }

    // ── Approval process flow ─────────────────────────────────────────────────

    public function storeStep(Request $request): RedirectResponse
    {
        FleetJobCardApprovalStep::create($this->validateStep($request) + ['created_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.step_saved'));
    }

    public function updateStep(Request $request, FleetJobCardApprovalStep $step): RedirectResponse
    {
        $step->update($this->validateStep($request) + ['updated_by' => Auth::id()]);

        return back()->with('success', __('fleet_job_cards.setup.step_saved'));
    }

    public function destroyStep(FleetJobCardApprovalStep $step): RedirectResponse
    {
        $step->delete();

        return back()->with('success', __('fleet_job_cards.setup.step_deleted'));
    }

    public function toggleStep(FleetJobCardApprovalStep $step): RedirectResponse
    {
        $step->update(['is_active' => ! $step->is_active]);

        return back()->with('success', __('fleet_job_cards.setup.status_changed'));
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'card_number_prefix'        => ['required', 'string', 'max:20'],
            'next_card_number'          => ['required', 'integer', 'min:1'],
            'require_approval'          => ['boolean'],
            'require_mechanic_feedback' => ['boolean'],
            'allow_parts_without_stock' => ['boolean'],
            'notify_on_assignment'      => ['boolean'],
            'notify_on_completion'      => ['boolean'],
            'default_labour_rate'       => ['nullable', 'numeric', 'min:0'],
        ]);

        FleetJobCardSetting::current()->update($data);

        return back()->with('success', __('fleet_job_cards.setup.settings_saved'));
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateServiceType(Request $request): array
    {
        return $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'code'           => ['nullable', 'string', 'max:40'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'standard_hours' => ['nullable', 'numeric', 'min:0'],
            'standard_cost'  => ['nullable', 'numeric', 'min:0'],
            'is_active'      => ['boolean'],
        ]);
    }

    private function validateCategory(Request $request): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['boolean'],
        ]);
    }

    private function validatePart(Request $request): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:fleet_job_card_part_categories,id'],
            'name'        => ['required', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit'        => ['nullable', 'string', 'max:30'],
            'unit_price'  => ['nullable', 'numeric', 'min:0'],
            'is_active'   => ['boolean'],
        ]);
    }

    private function validateStep(Request $request): array
    {
        return $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'sequence'         => ['required', 'integer', 'min:1', 'max:100'],
            'approver_type'    => ['required', 'in:user,role,line_manager'],
            'approver_user_id' => ['nullable', 'required_if:approver_type,user', 'integer', 'exists:users,id'],
            'approver_role'    => ['nullable', 'required_if:approver_type,role', 'string', 'max:100'],
            'min_amount'       => ['nullable', 'numeric', 'min:0'],
            'is_active'        => ['boolean'],
        ]);
    }
}
