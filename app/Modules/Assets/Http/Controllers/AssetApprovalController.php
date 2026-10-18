<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\ApproveAssetApprovalAction;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Actions the asset approval queue (assignment / procurement / etc.). All reads
 * are RLS-scoped to the active company; no manual company filter is needed.
 */
class AssetApprovalController extends Controller
{
    private const STATUS_COLORS = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'rejected'  => 'error',
        'cancelled' => 'secondary',
    ];

    public function index(Request $request): Response
    {
        $query = AssetApproval::query()
            ->with(['requester.person:id,user_id,first_name,surname', 'approvable'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest();

        $approvals = $query->paginate(15)->withQueryString();
        $approvals->getCollection()->transform(fn (AssetApproval $a) => $this->toArray($a));

        return Inertia::render('Modules/Assets/Approvals/Index', [
            'approvals' => $approvals,
            'filters'   => $request->only('status', 'type'),
            'stats'     => [
                'pending'  => AssetApproval::where('status', 'pending')->count(),
                'approved' => AssetApproval::where('status', 'approved')->count(),
                'rejected' => AssetApproval::where('status', 'rejected')->count(),
                'total'    => AssetApproval::count(),
            ],
            'typeOptions' => collect(ApprovalType::cases())
                ->map(fn (ApprovalType $t) => ['value' => $t->value, 'label' => $t->label()])
                ->values(),
        ]);
    }

    public function pending(Request $request): Response
    {
        $userId = (int) Auth::id();

        // Approvals whose CURRENT step is assigned to me and still pending.
        $pending = AssetApproval::query()
            ->where('status', 'pending')
            ->with(['requester.person:id,user_id,first_name,surname', 'approvable'])
            ->whereHas('steps', fn ($q) => $q
                ->whereColumn('step_number', 'asset_approvals.current_step')
                ->where('approver_id', $userId)
                ->where('status', 'pending'))
            ->latest()
            ->get()
            ->map(fn (AssetApproval $a) => $this->toArray($a))
            ->values();

        return Inertia::render('Modules/Assets/Approvals/PendingQueue', [
            'pending' => $pending,
        ]);
    }

    public function show(AssetApproval $approval): Response
    {
        $approval->load([
            'requester.person:id,user_id,first_name,surname',
            'approvable',
            'steps.approver.person:id,user_id,first_name,surname',
            'comments.user.person:id,user_id,first_name,surname',
        ]);

        $data = $this->toArray($approval);
        $data['requested_by'] = $approval->requested_by;
        $data['steps'] = $approval->steps
            ->sortBy('step_number')
            ->map(fn ($s) => [
                'step_number' => $s->step_number,
                'approver_id' => $s->approver_id,
                'approver_name' => $this->personName($s->approver),
                'decision'    => $s->status?->value,
                'decided_at'  => $s->decided_at?->toDateTimeString(),
                'comments'    => $s->notes,
            ])->values();
        $data['comments'] = $approval->comments
            ->map(fn ($c) => [
                'id'        => $c->id,
                'user_id'   => $c->user_id,
                'user_name' => $this->personName($c->user),
                'body'      => $c->comment,
                'created_at' => $c->created_at?->toDateTimeString(),
            ])->values();

        return Inertia::render('Modules/Assets/Approvals/Show', [
            'approval' => $data,
            'canAct'   => $this->canAct($approval, (int) Auth::id()),
        ]);
    }

    public function act(Request $request, AssetApproval $approval, ApproveAssetApprovalAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'comments' => 'nullable|string|max:2000',
        ]);

        if (! $approval->isPending()) {
            return back()->with('error', 'This approval has already been finalised.');
        }

        if (! $this->canAct($approval, (int) Auth::id())) {
            return back()->with('error', 'This approval step is not assigned to you.');
        }

        try {
            $action->execute(
                $approval,
                (int) Auth::id(),
                $validated['decision'] === 'approve',
                $validated['comments'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Decision recorded.');
    }

    /** Whether $userId owns the current pending step of $approval. */
    private function canAct(AssetApproval $approval, int $userId): bool
    {
        if (! $approval->isPending()) {
            return false;
        }

        return $approval->steps()
            ->where('step_number', $approval->current_step)
            ->where('approver_id', $userId)
            ->where('status', 'pending')
            ->exists();
    }

    private function toArray(AssetApproval $a): array
    {
        return [
            'id'           => $a->id,
            'uuid'         => $a->uuid,
            'type'         => $a->type instanceof ApprovalType ? $a->type->label() : (string) $a->type,
            'current_step' => $a->current_step,
            'total_steps'  => $a->total_steps,
            'status'       => [
                'value' => $a->status?->value,
                'label' => ucfirst($a->status?->value ?? 'pending'),
                'color' => self::STATUS_COLORS[$a->status?->value] ?? 'secondary',
            ],
            'amount'     => $this->resolveAmount($a),
            'requester'  => $a->requester ? [
                'id'   => $a->requester->id,
                'name' => $this->personName($a->requester),
            ] : null,
            'created_at' => $a->created_at?->toDateTimeString(),
        ];
    }

    /** Best-effort monetary context for the request, from the linked record. */
    private function resolveAmount(AssetApproval $a): ?float
    {
        $subject = $a->approvable;

        return match (true) {
            $subject === null => null,
            isset($subject->estimated_total_cost) => (float) $subject->estimated_total_cost,
            isset($subject->purchase_cost) => (float) $subject->purchase_cost,
            default => null,
        };
    }

    private function personName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->person->first_name ?? '') . ' ' . ($user->person->surname ?? ''));

        return $name !== '' ? $name : "User #{$user->id}";
    }
}
