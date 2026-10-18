<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetJobCard;
use App\Modules\Fleet\Models\FleetJobCardApproval;
use App\Modules\Fleet\Services\FleetJobCardWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The job card approvals queue: cards sitting at a step this user can action.
 */
class FleetJobCardApprovalController extends Controller
{
    public function __construct(private readonly FleetJobCardWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $userId = Auth::id();

        $cards = FleetJobCard::query()
            ->with(['vehicle:id,registration_number,make,model', 'approvals'])
            ->awaitingApproval()
            ->when(! $request->boolean('all'), function ($q) use ($userId) {
                // Only the steps this user owns, at the position the card has reached.
                $q->whereHas('approvals', fn ($a) => $a
                    ->where('status', 'pending')
                    ->where('approver_user_id', $userId));
            })
            ->latest('opened_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/Fleet/JobCards/Approvals', [
            'cards'   => $cards,
            'showAll' => $request->boolean('all'),
        ]);
    }

    public function approve(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        return $this->decide($request, $jobCard, true);
    }

    public function reject(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        return $this->decide($request, $jobCard, false);
    }

    private function decide(Request $request, FleetJobCard $jobCard, bool $approved): RedirectResponse
    {
        $data = $request->validate([
            'comment' => [$approved ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);

        $approval = $jobCard->approvals()
            ->where('status', 'pending')
            ->orderBy('sequence')
            ->first();

        if (! $approval) {
            return back()->with('error', __('fleet_job_cards.flash.nothing_to_approve'));
        }

        // A step assigned to a specific person may only be actioned by that person.
        if ($approval->approver_user_id && (int) $approval->approver_user_id !== (int) Auth::id()) {
            return back()->with('error', __('fleet_job_cards.flash.not_your_step'));
        }

        $this->workflow->decide($jobCard, $approval, $approved, $data['comment'] ?? null);

        return back()->with('success', $approved
            ? __('fleet_job_cards.flash.approved')
            : __('fleet_job_cards.flash.rejected'));
    }
}
