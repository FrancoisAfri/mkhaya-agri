<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Str;

/**
 * Purchase request lifecycle: create → add items → submit (validate → start
 * approval). Submission runs the validation gate; blocking violations stop it.
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly ProcurementValidationService $validator,
        private readonly ProcurementApprovalService $approvals,
    ) {}

    public function create(array $data, ?int $userId = null): PurchaseRequest
    {
        $data['request_number'] ??= $this->generateNumber();
        $data['requester_id'] ??= $userId;
        $data['created_by'] ??= $userId;
        $data['status'] ??= 'draft';

        $request = PurchaseRequest::create($data);
        $this->history($request, 'created', null, 'draft', $userId, 'Request created.');

        return $request;
    }

    /** @return array{ok:bool,blocks:array,warnings:array,message:string} */
    public function submit(PurchaseRequest $request, ?int $userId = null): array
    {
        $request->recalcTotal();
        $request->refresh();

        $result = $this->validator->validate($request);
        $this->validator->persistViolations($request, array_merge($result['blocks'], $result['warnings']));

        if (! empty($result['blocks'])) {
            $from = $request->status;
            $request->update(['status' => 'validation_failed']);
            $this->history($request, 'validation_failed', $from, 'validation_failed', $userId, 'Blocked by policy/budget checks.');

            return ['ok' => false, 'blocks' => $result['blocks'], 'warnings' => $result['warnings'],
                'message' => 'Submission blocked — resolve the flagged issues (or request an override/exception).'];
        }

        $from = $request->status;
        $request->update(['status' => 'pending_approval']);
        // A company that routes requisitions through the workflow engine gets its approval path
        // there; the policy and budget checks above apply either way.
        $workflows = app(\App\Modules\Workflow\Services\Workflows::class);
        if ($workflows->handles('procurement.requisition', $request->company_id)) {
            $workflows->emit('procurement.requisition.submitted', 'procurement.requisition', (string) $request->uuid, (int) ($request->requester_id ?? $userId), ['policy_chain' => $result['chain']],
                'pr:' . $request->uuid . ':' . $request->history()->where('action', 'submitted')->count());
            $this->history($request, 'submitted', $from, 'pending_approval', $userId, 'Submitted for approval (workflow).');

            return ['ok' => true, 'blocks' => [], 'warnings' => $result['warnings'], 'message' => 'Submitted — routed for approval.'];
        }
        $this->approvals->start($request, $result['chain'], $userId);
        $this->history($request, 'submitted', $from, 'pending_approval', $userId, 'Submitted for approval.');

        return ['ok' => true, 'blocks' => [], 'warnings' => $result['warnings'],
            'message' => 'Submitted — routed for approval.'];
    }

    public function cancel(PurchaseRequest $request, ?int $userId = null): void
    {
        $from = $request->status;
        $request->update(['status' => 'cancelled']);
        $this->history($request, 'cancelled', $from, 'cancelled', $userId, 'Request cancelled.');
    }

    public function history(PurchaseRequest $request, string $action, ?string $from, ?string $to, ?int $userId, ?string $note): void
    {
        $request->history()->create([
            'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'performed_by' => $userId, 'note' => $note,
        ]);
    }

    private function generateNumber(): string
    {
        $max = (int) PurchaseRequest::withTrashed()->max('id');

        return 'PR'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
    }
}
