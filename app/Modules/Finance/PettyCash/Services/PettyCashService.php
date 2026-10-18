<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashApproval;
use App\Modules\Finance\PettyCash\Models\PettyCashAttachment;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use App\Modules\Finance\PettyCash\Models\PettyCashTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashService
{
    public function createPettyCashFloat(array $data, int $createdBy): PettyCashFloat
    {
        $data['opening_balance'] = $data['float_limit'];
        $data['current_balance'] = $data['float_limit'];

        $float = PettyCashFloat::create($data);

        $this->recordTransaction($float, null, 'replenishment', $data['float_limit'], 0, $createdBy, 'Initial float setup');

        return $float;
    }

    public function assignPettyCashCustodian(PettyCashFloat $float, int $custodianId): PettyCashFloat
    {
        $float->update(['custodian_id' => $custodianId]);

        return $float->fresh();
    }

public function requestPettyCash(array $data, int $requestorId): PettyCashRequest
{
    $float = PettyCashFloat::findOrFail($data['float_id']);

    if ($float->status !== 'active') {
        throw ValidationException::withMessages([
            'float_id' => 'This float is not active.',
        ]);
    }

    if ($float->current_balance < $data['amount_requested']) {
        throw ValidationException::withMessages([
            'amount_requested' => 'Insufficient float balance.',
        ]);
    }

    return DB::transaction(function () use ($data, $requestorId, $float) {
        return PettyCashRequest::create([
            ...$data,
            'requestor_id'   => $requestorId,
            'custodian_id'   => $float->custodian_id,
            'status'         => 'pending',
            'requested_at'   => now(),
            'receipt_due_at' => now()->addHours(48),
        ]);
    });
}

    public function approvePettyCashRequest(PettyCashRequest $request, int $approverId, ?string $comments = null): PettyCashRequest
    {
        abort_if($request->status !== 'pending', 422, 'Only pending requests can be approved.');
        abort_if($request->requestor_id === $approverId, 403, 'You cannot approve your own request.');

        return DB::transaction(function () use ($request, $approverId, $comments) {
            $request->update([
                'status'         => 'approved',
                'approver_id'    => $approverId,
                'amount_approved'=> $request->amount_requested,
                'approved_at'    => now(),
            ]);

            PettyCashApproval::create([
                'request_id'  => $request->id,
                'approver_id' => $approverId,
                'action'      => 'approved',
                'comments'    => $comments,
                'acted_at'    => now(),
            ]);

            return $request->fresh();
        });
    }

    public function rejectPettyCashRequest(PettyCashRequest $request, int $approverId, string $reason): PettyCashRequest
    {
        abort_if($request->status !== 'pending', 422, 'Only pending requests can be rejected.');

        return DB::transaction(function () use ($request, $approverId, $reason) {
            $request->update([
                'status'           => 'rejected',
                'approver_id'      => $approverId,
                'rejection_reason' => $reason,
                'rejected_at'      => now(),
            ]);

            PettyCashApproval::create([
                'request_id'  => $request->id,
                'approver_id' => $approverId,
                'action'      => 'rejected',
                'comments'    => $reason,
                'acted_at'    => now(),
            ]);

            return $request->fresh();
        });
    }

    public function issuePettyCash(PettyCashRequest $request, int $issuedBy): PettyCashRequest
    {
        abort_if($request->status !== 'approved', 422, 'Request must be approved before issuing cash.');

        $float = $request->float;

        abort_if($float->current_balance < $request->amount_approved, 422, 'Insufficient float balance to issue cash.');

        return DB::transaction(function () use ($request, $float, $issuedBy) {
            $amountToIssue = $request->amount_approved;

            $request->update([
                'status'        => 'issued',
                'amount_issued' => $amountToIssue,
                'issued_at'     => now(),
            ]);

            $this->recordTransaction(
                $float, $request, 'issue',
                -$amountToIssue, $float->current_balance, $issuedBy,
                "Cash issued for: {$request->purpose}"
            );

            $float->decrement('current_balance', $amountToIssue);

            PettyCashApproval::create([
                'request_id'  => $request->id,
                'approver_id' => $issuedBy,
                'action'      => 'issued',
                'acted_at'    => now(),
            ]);

            return $request->fresh();
        });
    }

    public function recordPettyCashSpend(PettyCashRequest $request, array $data): PettyCashRequest
    {
        abort_if($request->status !== 'issued', 422, 'Can only record spend on issued requests.');

        $request->update([
            'amount_spent'    => $data['amount_spent'],
            'amount_returned' => $data['amount_returned'] ?? 0,
            'notes'           => $data['notes'] ?? $request->notes,
            'status'          => 'settled',
            'settled_at'      => now(),
        ]);

        if (($data['amount_returned'] ?? 0) > 0) {
            $float = $request->float;
            $this->recordTransaction(
                $float, $request, 'adjustment',
                $data['amount_returned'], $float->current_balance, auth()->id(),
                'Change returned from petty cash'
            );
            $float->increment('current_balance', $data['amount_returned']);
        }

        return $request->fresh();
    }

    public function reconcilePettyCash(array $data, int $reconciledBy): PettyCashReconciliation
    {
        $float = PettyCashFloat::findOrFail($data['float_id']);

        abort_if($reconciledBy === $float->custodian_id && !auth()->user()->hasRole(['super admin', 'administrator', 'finance manager']), 403, 'Custodian cannot approve their own reconciliation.');

        return DB::transaction(function () use ($data, $reconciledBy, $float) {
            $expectedBalance = $data['opening_balance'] + $data['total_replenishments'] - $data['total_issued'];
            $variance        = $data['cash_on_hand'] - $expectedBalance;

            $reconciliation = PettyCashReconciliation::create([
                ...$data,
                'reconciled_by'  => $reconciledBy,
                'closing_balance'=> $data['cash_on_hand'],
                'variance'       => $variance,
                'status'         => 'submitted',
                'submitted_at'   => now(),
            ]);

            if ($variance !== 0.0) {
                $this->recordTransaction(
                    $float, null, 'reconciliation_variance',
                    $variance, $float->current_balance, $reconciledBy,
                    "Reconciliation variance: R{$variance}"
                );

                $float->update(['current_balance' => $data['cash_on_hand']]);
            }

            $float->update(['last_reconciled_at' => now()]);

            return $reconciliation;
        });
    }

    public function replenishPettyCashFloat(PettyCashFloat $float, float $amount, int $replenishedBy, ?string $reference = null): PettyCashTransaction
    {
        return DB::transaction(function () use ($float, $amount, $replenishedBy, $reference) {
            $transaction = $this->recordTransaction(
                $float, null, 'replenishment',
                $amount, $float->current_balance, $replenishedBy,
                $reference ?? 'Float replenishment'
            );

            $float->increment('current_balance', $amount);

            return $transaction;
        });
    }

    public function uploadAttachment(PettyCashRequest|PettyCashReconciliation $parent, UploadedFile $file, int $uploadedBy, string $type = 'receipt'): PettyCashAttachment
    {
        $path     = $file->store('petty-cash/attachments', 'public');
        $isRequest = $parent instanceof PettyCashRequest;

        return PettyCashAttachment::create([
            'request_id'       => $isRequest ? $parent->id : null,
            'reconciliation_id'=> $isRequest ? null : $parent->id,
            'uploaded_by'      => $uploadedBy,
            'file_name'        => basename($path),
            'original_name'    => $file->getClientOriginalName(),
            'file_path'        => $path,
            'file_size'        => $file->getSize(),
            'mime_type'        => $file->getMimeType(),
            'attachment_type'  => $type,
        ]);
    }

    private function recordTransaction(
        PettyCashFloat $float,
        ?PettyCashRequest $request,
        string $type,
        float $amount,
        float $balanceBefore,
        int $performedBy,
        ?string $description = null
    ): PettyCashTransaction {
        return PettyCashTransaction::create([
            'float_id'       => $float->id,
            'request_id'     => $request?->id,
            'performed_by'   => $performedBy,
            'transaction_type'=> $type,
            'amount'         => abs($amount),
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceBefore + $amount,
            'description'    => $description,
            'performed_at'   => now(),
        ]);
    }
}
