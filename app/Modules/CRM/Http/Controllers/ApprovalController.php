<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmApproval;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmNotification;
use App\Modules\CRM\Services\CrmNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal approval flows — discount / deal approvals (spec section 10).
 */
class ApprovalController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private CrmNotifier $notifier)
    {
    }

    public function index(): Response
    {
        $me = Auth::id();

        $pending = CrmApproval::with(['deal:id,title', 'requestedBy.person'])
            ->where('approver_id', $me)->where('status', 'pending')
            ->latest()->get()->map(fn ($a) => $this->present($a));

        $mine = CrmApproval::with(['deal:id,title', 'approver.person'])
            ->where('requested_by', $me)
            ->latest()->limit(50)->get()->map(fn ($a) => $this->present($a));

        $notifications = CrmNotification::where('user_id', $me)
            ->latest()->limit(20)->get()
            ->map(fn ($n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
                'read' => $n->read_at !== null, 'at' => $n->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Modules/CRM/Approvals', [
            'pending'       => $pending,
            'mine'          => $mine,
            'notifications' => $notifications,
            'unreadCount'   => CrmNotification::where('user_id', $me)->whereNull('read_at')->count(),
            'types'         => CrmApproval::TYPES,
            'statuses'      => CrmApproval::STATUSES,
            'deals'         => CrmDeal::where('status', 'open')->orderBy('title')->get(['id', 'title', 'amount']),
            'users'         => $this->tenantPeopleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'deal_id'       => 'nullable|exists:crm_deals,id',
            'type'          => 'required|in:discount,deal,custom',
            'title'         => 'required|string|max:200',
            'amount'        => 'nullable|numeric|min:0',
            'approver_id'   => 'required|exists:users,id',
            'request_note'  => 'nullable|string|max:1000',
            'mentioned'     => 'nullable|array',
            'mentioned.*'   => 'integer|exists:users,id',
        ]);

        $approval = CrmApproval::create([
            'uuid'         => Str::uuid(),
            'deal_id'      => $data['deal_id'] ?? null,
            'type'         => $data['type'],
            'title'        => $data['title'],
            'amount'       => $data['amount'] ?? null,
            'requested_by' => Auth::id(),
            'approver_id'  => $data['approver_id'],
            'status'       => 'pending',
            'request_note' => $data['request_note'] ?? null,
            'mentioned'    => $data['mentioned'] ?? [],
        ]);

        $this->notifier->notify(
            (int) $data['approver_id'], 'approval_requested',
            'Approval requested: ' . $approval->title,
            $approval->request_note,
            route('crm.approvals', absolute: false),
            'approval', $approval->id,
        );
        $this->notifier->mention($data['mentioned'] ?? [], 'You were mentioned on an approval: ' . $approval->title, $approval->request_note, route('crm.approvals', absolute: false), 'approval', $approval->id);

        return back()->with('success', 'Approval requested.');
    }

    public function decide(Request $request, CrmApproval $approval): RedirectResponse
    {
        abort_if($approval->approver_id !== Auth::id(), 403, 'Only the assigned approver can decide.');
        abort_if($approval->status !== 'pending', 422, 'This approval has already been decided.');

        $data = $request->validate([
            'status'        => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:1000',
        ]);

        $approval->update([
            'status'        => $data['status'],
            'decision_note' => $data['decision_note'] ?? null,
            'decided_by'    => Auth::id(),
            'decided_at'    => now(),
        ]);

        if ($approval->requested_by) {
            $this->notifier->notify(
                (int) $approval->requested_by, 'approval_decided',
                'Approval ' . $data['status'] . ': ' . $approval->title,
                $approval->decision_note,
                route('crm.approvals', absolute: false),
                'approval', $approval->id,
            );
        }

        return back()->with('success', 'Decision recorded.');
    }

    public function cancel(CrmApproval $approval): RedirectResponse
    {
        abort_if($approval->requested_by !== Auth::id(), 403);
        abort_if($approval->status !== 'pending', 422, 'Only pending requests can be cancelled.');

        $approval->update(['status' => 'cancelled']);

        return back()->with('success', 'Request cancelled.');
    }

    private function present(CrmApproval $a): array
    {
        return [
            'id'            => $a->id,
            'uuid'          => $a->uuid,
            'type'          => $a->type,
            'type_label'    => CrmApproval::TYPES[$a->type] ?? $a->type,
            'title'         => $a->title,
            'amount'        => $a->amount !== null ? (float) $a->amount : null,
            'deal'          => $a->deal?->title,
            'requested_by'  => $a->requestedBy?->person?->first_name,
            'approver'      => $a->approver?->person?->first_name,
            'status'        => $a->status,
            'status_label'  => CrmApproval::STATUSES[$a->status]['label'] ?? $a->status,
            'status_color'  => CrmApproval::STATUSES[$a->status]['color'] ?? 'blue-grey',
            'request_note'  => $a->request_note,
            'decision_note' => $a->decision_note,
            'created_at'    => $a->created_at?->diffForHumans(),
            'decided_at'    => $a->decided_at?->diffForHumans(),
        ];
    }
}
