<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingApproval;
use App\Modules\Fleet\Models\FleetBookingApprovalConfig;
use App\Modules\Fleet\Models\FleetBookingApprovalDelegation;
use App\Modules\Fleet\Models\FleetBookingApprovalToken;
use App\Modules\Fleet\Notifications\FleetBookingStatusNotification;
use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetBookingApprovalQueueController extends Controller
{
    use SafeNotifies;

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $userId = Auth::id();

        // Pending steps assigned to me (or delegated to me)
        $delegatorIds = FleetBookingApprovalDelegation::active()
            ->where('delegate_id', $userId)
            ->pluck('delegator_id')
            ->push($userId)
            ->unique();

        // Role-based step configs where the current user holds the matching role
        $userRoleNames     = Auth::user()->roles->pluck('name');
        $roleConfigIds     = FleetBookingApprovalConfig::where('approver_type', 'role')
            ->when($userRoleNames->isNotEmpty(), fn($q) => $q->whereIn('role_name', $userRoleNames))
            ->pluck('id');

        $pendingSteps = DB::table('fleet_booking_approvals as fba')
            ->join('fleet_bookings as fb', 'fb.id', '=', 'fba.booking_id')
            ->join('fleet_booking_approval_configs as cfg', 'cfg.id', '=', 'fba.config_step_id')
            ->join('fleet_vehicles as v', 'v.id', '=', 'fb.vehicle_id')
            ->join('users as ru', 'ru.id', '=', 'fb.requester_id')
            ->leftJoin('hr_people as rp', 'rp.user_id', '=', 'ru.id')
            ->leftJoin('fleet_drivers as fd', 'fd.id', '=', 'fb.driver_id')
            ->leftJoin('users as du', 'du.id', '=', 'fd.user_id')
            ->leftJoin('hr_people as dp', 'dp.user_id', '=', 'du.id')
            ->where(function ($q) use ($delegatorIds, $roleConfigIds) {
                $q->whereIn('fba.approver_id', $delegatorIds);
                if ($roleConfigIds->isNotEmpty()) {
                    $q->orWhereIn('fba.config_step_id', $roleConfigIds);
                }
            })
            ->where('fba.status', 'pending') // 'waiting' steps are not yet actionable
            ->whereNull('fb.deleted_at')
            ->selectRaw("
                fba.id as approval_id,
                fb.id as booking_id,
                fb.reference,
                fb.workflow_state,
                fb.purpose,
                fb.destination,
                fb.departure_at,
                fb.expected_return_at,
                fb.is_local_trip,
                fb.project,
                fb.passenger_count,
                fb.requester_id,
                fba.step_number,
                cfg.step_label,
                cfg.approver_type,
                TRIM(COALESCE(v.make,'') || ' ' || COALESCE(v.model,'') || ' (' || COALESCE(v.registration_number,'') || ')') as vehicle,
                TRIM(COALESCE(rp.first_name,'') || ' ' || COALESCE(rp.surname,'')) as requester_name,
                du.id as driver_user_id,
                TRIM(COALESCE(dp.first_name,'') || ' ' || COALESCE(dp.surname,'')) as driver_name,
                fba.approver_id,
                fba.created_at as submitted_at
            ")
            ->orderBy('fb.departure_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->values()->all();

        // Recent approvals actioned by me
        $recentActioned = DB::table('fleet_booking_approvals as fba')
            ->join('fleet_bookings as fb', 'fb.id', '=', 'fba.booking_id')
            ->join('fleet_booking_approval_configs as cfg', 'cfg.id', '=', 'fba.config_step_id')
            ->join('users as ru', 'ru.id', '=', 'fb.requester_id')
            ->join('hr_people as rp', 'rp.user_id', '=', 'ru.id')
            ->where('fba.approver_id', $userId)
            ->whereIn('fba.status', ['approved', 'rejected'])
            ->whereNull('fb.deleted_at')
            ->selectRaw("
                fba.id as approval_id,
                fb.reference,
                fba.step_number,
                cfg.step_label,
                fba.status,
                fba.comments,
                fba.action_at,
                TRIM(COALESCE(rp.first_name,'') || ' ' || COALESCE(rp.surname,'')) as requester_name
            ")
            ->orderByDesc('fba.action_at')
            ->limit(30)
            ->get()
            ->map(fn($r) => (array) $r)
            ->values()->all();

        // My active delegation (I delegated away)
        $myDelegation = DB::table('fleet_booking_approval_delegations as d')
            ->join('hr_people as hp', 'hp.user_id', '=', 'd.delegate_id')
            ->where('d.delegator_id', $userId)
            ->where('d.is_active', true)
            ->where('d.start_date', '<=', now()->toDateString())
            ->where('d.end_date', '>=', now()->toDateString())
            ->whereNull('d.deleted_at')
            ->selectRaw("
                d.id,
                d.start_date, d.end_date, d.reason,
                TRIM(COALESCE(hp.first_name,'') || ' ' || COALESCE(hp.surname,'')) as delegate_name
            ")
            ->first();

        // My delegations (all, for management tab)
        $delegations = DB::table('fleet_booking_approval_delegations as d')
            ->join('hr_people as hp', 'hp.user_id', '=', 'd.delegate_id')
            ->where('d.delegator_id', $userId)
            ->whereNull('d.deleted_at')
            ->selectRaw("
                d.id, d.start_date, d.end_date, d.reason, d.is_active,
                TRIM(COALESCE(hp.first_name,'') || ' ' || COALESCE(hp.surname,'')) as delegate_name
            ")
            ->orderByDesc('d.start_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->values()->all();

        // Employees for delegate selector
        $employees = DB::table('hr_people as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('u.id', '!=', $userId)
            ->orderByRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,''))")
            ->selectRaw("u.id as user_id, TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as name")
            ->get();

        return Inertia::render('Modules/Fleet/Approvals/Index', [
            'pendingSteps'   => $pendingSteps,
            'recentActioned' => $recentActioned,
            'myDelegation'   => $myDelegation ? (array) $myDelegation : null,
            'delegations'    => $delegations,
            'employees'      => $employees,
            'summary'        => ['total_pending' => count($pendingSteps)],
        ]);
    }

    // ── Approve a step ────────────────────────────────────────────────────────

    public function approve(Request $request, FleetBookingApproval $approval): RedirectResponse
    {
        $request->validate(['comments' => 'nullable|string|max:500']);

        if ($approval->status !== 'pending') {
            return back()->with('error', 'This step has already been actioned.');
        }

        $approval->update([
            'status'      => 'approved',
            'approver_id' => Auth::id(),
            'comments'    => $request->comments,
            'action_at'   => now(),
        ]);

        $this->advanceWorkflow($approval->booking);

        return back()->with('success', 'Booking step approved.');
    }

    // ── Reject a step ─────────────────────────────────────────────────────────

    public function reject(Request $request, FleetBookingApproval $approval): RedirectResponse
    {
        $request->validate(['comments' => 'required|string|max:500']);

        if ($approval->status !== 'pending') {
            return back()->with('error', 'This step has already been actioned.');
        }

        $approval->update([
            'status'      => 'rejected',
            'approver_id' => Auth::id(),
            'comments'    => $request->comments,
            'action_at'   => now(),
        ]);

        $booking = $approval->booking;
        $booking->update(['workflow_state' => 'cancelled', 'cancellation_reason' => "Rejected at step {$approval->step_number}: {$request->comments}"]);

        // Invalidate any pending tokens for this booking
        FleetBookingApprovalToken::where('booking_id', $booking->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Notify requester
        $rejectedRequester = User::find($booking->requester_id);
        if ($rejectedRequester) {
            $this->safeNotify($rejectedRequester, new FleetBookingStatusNotification($booking, 'rejected', $request->comments));
        }

        return back()->with('success', 'Booking rejected and requester notified.');
    }

    // ── Delegation CRUD ───────────────────────────────────────────────────────

    public function storeDelegation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delegate_id' => 'required|exists:users,id|different:' . Auth::id(),
            'start_date'  => 'required|date|after_or_equal:today',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'nullable|string|max:500',
        ]);

        // Deactivate any overlapping active delegations
        FleetBookingApprovalDelegation::where('delegator_id', Auth::id())
            ->where('is_active', true)
            ->update(['is_active' => false]);

        FleetBookingApprovalDelegation::create([
            'delegator_id' => Auth::id(),
            'delegate_id'  => $data['delegate_id'],
            'start_date'   => $data['start_date'],
            'end_date'     => $data['end_date'],
            'reason'       => $data['reason'] ?? null,
            'is_active'    => true,
            'created_by'   => Auth::id(),
        ]);

        return back()->with('success', 'Approval authority delegated.');
    }

    public function destroyDelegation(FleetBookingApprovalDelegation $delegation): RedirectResponse
    {
        abort_unless($delegation->delegator_id === Auth::id(), 403);
        $delegation->update(['is_active' => false]);
        $delegation->delete();
        return back()->with('success', 'Delegation revoked.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveDelegateId(int $userId): int
    {
        $delegation = DB::table('fleet_booking_approval_delegations')
            ->where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->whereNull('deleted_at')
            ->first();

        return $delegation ? (int) $delegation->delegate_id : $userId;
    }

    // ── Workflow helper (mirrors FleetBookingController logic) ────────────────

    private function advanceWorkflow(FleetBooking $booking): void
    {
        $booking->refresh();

        // Activate the next waiting step sequentially
        $next = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'waiting')
            ->orderBy('step_number')
            ->first();

        if ($next) {
            $config = \App\Modules\Fleet\Models\FleetBookingApprovalConfig::find($next->config_step_id);

            // Re-check delegation for non-role steps: if the stored approver has since delegated, redirect
            $approverId = $next->approver_id;
            if ($approverId && (!$config || $config->approver_type !== 'role')) {
                $approverId = $this->resolveDelegateId($approverId);
            }

            $next->update(['status' => 'pending', 'approver_id' => $approverId]);

            if ($approverId) {
                if ($config && $config->approver_type === 'role' && $config->role_name) {
                    $this->safeNotifyAll(
                        User::role($config->role_name)->get(),
                        new \App\Modules\Fleet\Notifications\FleetBookingApprovalNotification($booking, $next->fresh())
                    );
                } else {
                    $nextApprover = User::find($approverId);
                    if ($nextApprover) {
                        $this->safeNotify($nextApprover, new \App\Modules\Fleet\Notifications\FleetBookingApprovalNotification($booking, $next->fresh()));
                    }
                }
            }
            return;
        }

        $stillPending = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->exists();

        if ($stillPending) return;

        // All steps done
        $booking->update([
            'workflow_state' => 'approved',
            'approved_at'    => now(),
            'approved_by'    => Auth::id(),
            'updated_by'     => Auth::id(),
            'version'        => ($booking->version ?? 0) + 1,
        ]);
        $booking->vehicle?->update(['status' => 'booked']);

        $fresh = $booking->fresh();

        $freshRequester = User::find($fresh->requester_id);
        if ($freshRequester) {
            $this->safeNotify($freshRequester, new FleetBookingStatusNotification($fresh, 'approved'));
        }

        $driverUserId = $fresh->driver?->user_id;
        if ($driverUserId && $driverUserId !== $fresh->requester_id) {
            $freshDriver = User::find($driverUserId);
            if ($freshDriver) {
                $this->safeNotify($freshDriver, new FleetBookingStatusNotification($fresh, 'approved_driver'));
            }
        }

        if (!empty($fresh->passenger_ids)) {
            foreach ($fresh->passenger_ids as $pid) {
                $passenger = User::find($pid);
                if ($passenger) {
                    $this->safeNotify($passenger, new FleetBookingStatusNotification($fresh, 'approved_passenger'));
                }
            }
        }
    }
}
