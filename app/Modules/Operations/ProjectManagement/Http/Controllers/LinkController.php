<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ProjectLinkService;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attaching another module's records to a project (PM-1042).
 *
 * This is the half of the integration story that reading alone cannot provide. A provider can list
 * every asset in the register; only a person can say *this* excavator is on *this* job — and until
 * somebody has, there is no committed cost to report and no equipment list to show.
 */
class LinkController extends Controller
{
    public function __construct(
        private readonly ProjectLinkService $links,
        private readonly ProviderRegistry $providers,
    ) {
    }

    /** Search a module for something to attach. */
    public function search(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ProjectLinkService::KINDS))],
            'q'    => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $results = $this->links->search($data['kind'], (string) ($data['q'] ?? ''));
        } catch (\InvalidArgumentException) {
            // The module is not available to this tenant. An empty list rather than a 422: the
            // picker should never have been offered, and a broken dialog helps nobody.
            $results = [];
        }

        return response()->json(['results' => $results]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'kind'      => ['required', Rule::in(array_keys(ProjectLinkService::KINDS))],
            'record_id' => ['required', 'string', 'max:64'],
            'label'     => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $this->links->link($project, $data['kind'], $data['record_id'], $data['label'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['record_id' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.link_created'));
    }

    public function destroy(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->links->unlink($project, $uuid);

        return back()->with('success', __('project_management.flash.link_removed'));
    }

    /**
     * Ask to buy something for this project (PM-1043).
     *
     * The other half of the procurement integration. Reading orders back tells a project manager
     * what has been spent; this is how the spending starts, without leaving the job to go and find
     * the purchasing module and re-type which project it is for.
     *
     * PM raises nothing itself: the provider opens Procurement's own front door so that module's
     * budget checks and approval chain apply. If they block it, that is reported here rather than
     * swallowed — a request sitting in `validation_failed` that somebody believes was submitted is
     * the failure this page exists to avoid.
     */
    public function raisePurchase(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        // Through the registry, not the container: the seam resolves to the local or the integrated
        // implementation depending on what this tenant holds, and the interface itself is not bound.
        $purchases = $this->providers->for(PurchaseProvider::class);

        abort_unless($purchases->canRaise(), 404);

        $data = $request->validate([
            'title'             => ['required', 'string', 'max:190'],
            'justification'     => ['nullable', 'string', 'max:2000'],
            'needed_by'         => ['nullable', 'date'],
            'delivery_location' => ['nullable', 'string', 'max:190'],
            'urgency'           => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'currency'          => ['nullable', 'string', 'size:3'],
            'items'                 => ['required', 'array', 'min:1', 'max:50'],
            'items.*.name'          => ['required', 'string', 'max:190'],
            'items.*.description'   => ['nullable', 'string', 'max:500'],
            'items.*.quantity'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $record = $purchases->raise($project->uuid, $data);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['title' => $e->getMessage()]);
        }

        // Linked and recorded together: the request exists whether or not Procurement accepted the
        // submission, and a project that could not see it would be missing a real commitment.
        $this->links->linkRaisedPurchase($project, $record);

        return $record->meta['submitted'] ?? false
            ? back()->with('success', __('project_management.flash.purchase_raised', [
                'number' => $record->meta['number'] ?? '',
            ]))
            // Not an error — the request was created. It is held, and it says why.
            : back()->with('warning', __('project_management.flash.purchase_held', [
                'number'  => $record->meta['number'] ?? '',
                'message' => $record->meta['message'] ?? '',
            ]));
    }
}
