<?php

namespace App\Modules\Finance\Claims\Services;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimApproval;
use App\Modules\Finance\Claims\Models\ClaimApprovalStep;
use App\Modules\Finance\Claims\Models\ClaimPaymentProcessor;
use App\Modules\Finance\Claims\Notifications\ClaimEventNotification;
use App\Modules\Finance\Claims\Services\ClaimsApprovalDelegationService;
use App\Modules\Finance\Claims\Models\ClaimAttachment;
use App\Modules\Finance\Claims\Models\ClaimItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClaimsService
{
    use SafeNotifies;
    public function __construct(
        private readonly ClaimsPolicyService              $policyService,
        private readonly ClaimsAiService                  $aiService,
        private readonly ClaimsPolicyGuard                $policyGuard,
        private readonly ClaimsApprovalDelegationService  $delegationService,
    ) {}

    // ── Phase 1: Core CRUD ────────────────────────────────────────────────────

    public function createClaim(array $data): Claim
    {
        return DB::transaction(function () use ($data) {
            $claim = Claim::create([
                'reference_number'   => Claim::generateReference(),
                'employee_id'        => $data['employee_id'] ?? Auth::id(),
                'category_id'        => $data['category_id'] ?? null,
                'title'              => $data['title'],
                'description'        => $data['description'] ?? null,
                'currency'           => $data['currency'] ?? 'ZAR',
                'total_amount'       => 0,
                'claim_date'         => $data['claim_date'],
                'status'             => 'draft',
                'created_by'         => Auth::id(),
            ]);

            activity('claims')
                ->causedBy(Auth::user())
                ->performedOn($claim)
                ->event('created')
                ->log("Claim {$claim->reference_number} created.");

            return $claim;
        });
    }

    public function addClaimItem(Claim $claim, array $data): ClaimItem
    {
        $claim->loadMissing('category');

        // If the category mandates receipts, every item is required to have one —
        // regardless of what the caller passes in the receipt_required field.
        $receiptRequired = ($claim->category?->requires_receipt && ! $claim->category?->is_travel)
            ? true
            : (isset($data['receipt_required']) ? (bool) $data['receipt_required'] : false);

        $item = $claim->items()->create([
            'item_date'       => $data['item_date'] ?? null,
            'description'     => $data['description'],
            'unit_price'      => $data['unit_price'],
            'quantity'        => $data['quantity'] ?? 1,
            'amount'          => $data['unit_price'] * ($data['quantity'] ?? 1),
            'item_type'       => $data['item_type'] ?? null,
            'receipt_required'=> $receiptRequired,
            'notes'           => $data['notes'] ?? null,
        ]);

        $claim->recalculateTotal();

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->withProperties(['item' => $item->description, 'amount' => $item->amount])
            ->event('item_added')
            ->log("Item \"{$item->description}\" added to {$claim->reference_number}.");

        return $item;
    }

    public function updateClaimItem(ClaimItem $item, array $data): ClaimItem
    {
        $item->update([
            'description'     => $data['description'],
            'unit_price'      => $data['unit_price'],
            'quantity'        => $data['quantity'] ?? 1,
            'amount'          => $data['unit_price'] * ($data['quantity'] ?? 1),
            'item_type'       => $data['item_type'] ?? $item->item_type,
            'receipt_required'=> $data['receipt_required'] ?? $item->receipt_required,
            'notes'           => $data['notes'] ?? $item->notes,
        ]);

        $item->claim->recalculateTotal();
        return $item->fresh();
    }

    public function removeClaimItem(ClaimItem $item): void
    {
        $claim = $item->claim;
        $item->delete();
        $claim->recalculateTotal();
    }

    public function uploadClaimReceipt(Claim $claim, UploadedFile $file, ?int $itemId = null, string $type = 'receipt', ?int $tripId = null): ClaimAttachment
    {
        $stored = (new ClaimAttachment())->saveFileToDatabase($file);

        $attachment = ClaimAttachment::create([
            'claim_id'              => $claim->id,
            'claim_item_id'         => $itemId,
            'claim_travel_trip_id'  => $tripId,
            'uuid'                  => $stored['uuid'],
            'original_name'         => $stored['original_name'],
            'mime'                  => $stored['mime'],
            'type'                  => $stored['type'],
            'data'                  => $stored['data'],
            'size'                  => $stored['size'],
            'attachment_type'       => $type,
            'uploaded_by'           => Auth::id(),
        ]);

        if ($itemId) {
            ClaimItem::where('id', $itemId)->update(['receipt_uploaded' => true]);
        }

        if ($tripId) {
            \App\Modules\Finance\Claims\Models\ClaimTravelTrip::where('id', $tripId)->update(['receipt_uploaded' => true]);
        }

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->withProperties(['file' => $file->getClientOriginalName()])
            ->event('receipt_uploaded')
            ->log("Receipt \"{$file->getClientOriginalName()}\" uploaded to {$claim->reference_number}.");

        return $attachment;
    }

    public function submitClaim(Claim $claim): Claim
    {
        abort_unless($claim->status === 'draft', 422, 'Only draft claims can be submitted.');

        $hasItems = $claim->items()->count() > 0 || $claim->travelTrips()->count() > 0;
        abort_unless($hasItems, 422, 'A claim must have at least one item or trip.');

        // Enforce receipt requirement.
        // Primary gate: if the category requires receipts, every item must have one uploaded.
        // Fallback gate: honour the per-item receipt_required flag for categories that don't mandate it globally.
        $claim->loadMissing('category');
        $categoryRequires = ($claim->category?->requires_receipt ?? false)
                         && ! ($claim->category?->is_travel ?? false);

        if (($claim->category?->requires_receipt ?? false) && ($claim->category?->is_travel ?? false)) {
            $missingTrips = $claim->travelTrips()->where('receipt_uploaded', false)
                ->get()->map(fn($t) => $t->trip_date->format('d M Y'))->all();
            if (count($missingTrips) > 0) {
                abort(422, 'A supporting document is required for each trip. Missing: ' . implode(', ', $missingTrips) . '.');
            }
        }

        $missingQuery = $claim->items()->where('receipt_uploaded', false);
        if (! $categoryRequires) {
            $missingQuery->where('receipt_required', true);
        }
        $missingReceipts = $missingQuery->pluck('description')->all();

        if (count($missingReceipts) > 0) {
            $list = implode(', ', array_map(fn($d) => '"' . $d . '"', $missingReceipts));
            abort(422, "A supporting document is required for: {$list}. Please upload the document(s) before submitting.");
        }

        // Run local policy rules (claim_policies table)
        $violations = $this->policyService->validateClaimPolicy($claim);
        $blocked    = collect($violations)->where('severity', 'block')->isNotEmpty();
        if ($blocked) {
            abort(422, 'Claim cannot be submitted — policy violations must be resolved.');
        }

        // Run central policy guard (EventRuleMap → PolicyRule engine)
        try {
            $claim->category?->is_travel
                ? $this->policyGuard->forTravelClaimSubmitted($claim->load('travelTrips', 'category'), Auth::id())
                : $this->policyGuard->forClaimSubmitted($claim->load('category', 'attachments'), Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('ClaimsPolicyGuard::forClaimSubmitted failed silently', ['error' => $e->getMessage()]);
        }

        // A company that routes claims through the workflow engine gets the engine's approval path
        // instead of the claim approval steps; the checks above still apply either way.
        $workflows = app(\App\Modules\Workflow\Services\Workflows::class);
        if ($workflows->handles('finance.claims', $claim->company_id ?? Auth::user()?->company_id)) {
            $claim->update(['status' => 'submitted', 'submitted_at' => now(), 'current_approver_id' => null, 'approval_level' => 1]);
            $workflows->emit('finance.claims.submitted', 'finance.claims', (string) $claim->uuid, (int) $claim->employee_id, [], 'claim:' . $claim->uuid . ':' . $claim->submitted_at?->timestamp);
            $this->aiService->analyseClaimRisk($claim);
            activity('claims')->causedBy(Auth::user())->performedOn($claim)->event('submitted')
                ->log("Claim {$claim->reference_number} submitted for approval (workflow).");

            return $claim->fresh();
        }

        $approver = $this->resolveFirstApprover($claim);

        $claim->update([
            'status'              => 'submitted',
            'submitted_at'        => now(),
            'current_approver_id' => $approver?->id,
            'approval_level'      => 1,
        ]);

        if ($approver) {
            ClaimApproval::create([
                'claim_id'    => $claim->id,
                'approver_id' => $approver->id,
                'level'       => 1,
                'action'      => 'pending',
            ]);
        }

        $this->aiService->analyseClaimRisk($claim);

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('submitted')
            ->log("Claim {$claim->reference_number} submitted for approval.");

        if ($approver) {
            $this->safeNotify($approver, new ClaimEventNotification(
                event:   'submitted',
                title:   'Claim Awaiting Your Approval',
                message: "Claim {$claim->reference_number} has been submitted and requires your approval.",
                url:     "/claims/approvals",
            ));
        }

        return $claim->fresh();
    }

    public function approveClaim(Claim $claim, array $data): Claim
    {
        abort_unless(in_array($claim->status, ['submitted', 'under_review']), 422, 'Claim cannot be approved in its current state.');

        // Block approval if receipts are required but missing
        $claim->loadMissing('category');
        if ($claim->category?->requires_receipt && $claim->category?->is_travel) {
            $missingTrips = $claim->travelTrips()->where('receipt_uploaded', false)
                ->get()->map(fn($t) => $t->trip_date->format('d M Y'))->all();
            if (count($missingTrips) > 0) {
                abort(422, 'Cannot approve: trip documents missing for — ' . implode(', ', $missingTrips) . '.');
            }
        } elseif ($claim->category?->requires_receipt) {
            $missing = $claim->items()->where('receipt_uploaded', false)->pluck('description')->all();
            if (count($missing) > 0) {
                abort(422, 'Cannot approve: supporting documents missing for — ' . implode(', ', $missing) . '.');
            }
        }

        try {
            $this->policyGuard->forClaimApproved($claim->load('category'), Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('ClaimsPolicyGuard::forClaimApproved failed silently', ['error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($claim, $data) {
            ClaimApproval::where('claim_id', $claim->id)
                ->where('approver_id', Auth::id())
                ->where('action', 'pending')
                ->update([
                    'action'      => 'approved',
                    'notes'       => $data['notes'] ?? null,
                    'actioned_at' => now(),
                ]);

            $nextApprover = $this->resolveNextApprover($claim);

            if ($nextApprover) {
                $claim->update([
                    'status'              => 'under_review',
                    'current_approver_id' => $nextApprover->id,
                    'approval_level'      => $claim->approval_level + 1,
                ]);

                ClaimApproval::create([
                    'claim_id'    => $claim->id,
                    'approver_id' => $nextApprover->id,
                    'level'       => $claim->approval_level + 1,
                    'action'      => 'pending',
                ]);
            } else {
                $claim->update([
                    'status'      => 'approved',
                    'approved_at' => now(),
                    'current_approver_id' => null,
                ]);
            }
        });

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->withProperties(['notes' => $data['notes'] ?? null])
            ->event('approved')
            ->log("Claim {$claim->reference_number} approved.");

        $fresh = $claim->fresh();

        if ($fresh->status === 'approved') {
            // Notify employee
            $employee = User::find($fresh->employee_id);
            if ($employee) {
                $this->safeNotify($employee, new ClaimEventNotification(
                    event:   'approved',
                    title:   'Claim Approved — Awaiting Payment',
                    message: "Your claim {$fresh->reference_number} has been fully approved and is now awaiting finance processing for payment.",
                    url:     "/claims/my/{$fresh->uuid}",
                ));
            }

            // Notify all configured payment processors (falls back to finance roles if none configured)
            $this->safeNotifyAll(
                ClaimPaymentProcessor::getProcessorUsers(),
                new ClaimEventNotification(
                    event:   'payment_ready',
                    title:   'Claim Ready for Payment',
                    message: "Claim {$fresh->reference_number} ({$fresh->employee()->value('email')}) has been approved and is awaiting payment processing.",
                    url:     '/claims/payments',
                )
            );
        } elseif ($fresh->current_approver_id) {
            // Moved to next approver
            $nextApprover = User::find($fresh->current_approver_id);
            if ($nextApprover) {
                $this->safeNotify($nextApprover, new ClaimEventNotification(
                    event:   'submitted',
                    title:   'Claim Awaiting Your Approval',
                    message: "Claim {$fresh->reference_number} requires your approval.",
                    url:     "/claims/approvals",
                ));
            }
        }

        return $fresh;
    }

    public function rejectClaim(Claim $claim, array $data): Claim
    {
        abort_unless(in_array($claim->status, ['submitted', 'under_review']), 422, 'Claim cannot be rejected in its current state.');

        try {
            $this->policyGuard->forClaimRejected($claim, $data['reason'] ?? '', Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('ClaimsPolicyGuard::forClaimRejected failed silently', ['error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($claim, $data) {
            ClaimApproval::where('claim_id', $claim->id)
                ->where('approver_id', Auth::id())
                ->where('action', 'pending')
                ->update([
                    'action'           => 'rejected',
                    'rejection_reason' => $data['reason'] ?? null,
                    'actioned_at'      => now(),
                ]);

            $claim->update([
                'status'           => 'rejected',
                'rejected_at'      => now(),
                'rejection_reason' => $data['reason'] ?? null,
                'current_approver_id' => null,
            ]);
        });

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->withProperties(['reason' => $data['reason'] ?? null])
            ->event('rejected')
            ->log("Claim {$claim->reference_number} rejected.");

        $employee = User::find($claim->employee_id);
        if ($employee) {
            $this->safeNotify($employee, new ClaimEventNotification(
                event:   'rejected',
                title:   'Claim Rejected',
                message: "Your claim {$claim->reference_number} was rejected." . ($data['reason'] ? " Reason: {$data['reason']}" : ''),
                url:     "/claims/my/{$claim->uuid}",
            ));
        }

        return $claim->fresh();
    }

    /**
     * The workflow engine's decision on a claim it routed (Workflow & Automation). Idempotent: a
     * claim already in the outcome's state is left alone. Approval puts it in the payment queue
     * exactly as the last level of the claim's own chain would.
     */
    public function finaliseByWorkflow(Claim $claim, string $outcome, ?int $actorId, ?string $comment): Claim
    {
        $claim = Claim::query()->lockForUpdate()->findOrFail($claim->id);
        $target = ['approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$outcome] ?? null;
        abort_unless($target, 422, 'Unknown workflow outcome.');
        if ($claim->status === $target || ($target === 'approved' && $claim->status === 'paid')) {
            return $claim;
        }
        abort_unless(in_array($claim->status, ['submitted', 'under_review'], true), 422, "Claim {$claim->reference_number} is {$claim->status}; the workflow outcome cannot be applied.");

        $claim->update(match ($target) {
            'approved' => ['status' => 'approved', 'approved_at' => now(), 'current_approver_id' => null],
            'rejected' => ['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $comment, 'current_approver_id' => null],
            'cancelled' => ['status' => 'cancelled', 'current_approver_id' => null],
        });
        activity('claims')->causedBy($actorId ? User::find($actorId) : null)->performedOn($claim)
            ->withProperties(['source' => 'workflow', 'comment' => $comment])->event($target)
            ->log("Claim {$claim->reference_number} {$target} through the workflow.");

        $employee = User::find($claim->employee_id);
        if ($target === 'approved') {
            if ($employee) {
                $this->safeNotify($employee, new ClaimEventNotification(event: 'approved', title: 'Claim Approved — Awaiting Payment',
                    message: "Your claim {$claim->reference_number} has been fully approved and is now awaiting finance processing for payment.", url: "/claims/my/{$claim->uuid}"));
            }
            $this->safeNotifyAll(ClaimPaymentProcessor::getProcessorUsers(), new ClaimEventNotification(event: 'payment_ready', title: 'Claim Ready for Payment',
                message: "Claim {$claim->reference_number} has been approved and is awaiting payment processing.", url: '/claims/payments'));
        } elseif ($target === 'rejected' && $employee) {
            $this->safeNotify($employee, new ClaimEventNotification(event: 'rejected', title: 'Claim Rejected',
                message: "Your claim {$claim->reference_number} was rejected." . ($comment ? " Reason: {$comment}" : ''), url: "/claims/my/{$claim->uuid}"));
        }

        return $claim->fresh();
    }

    public function payClaim(Claim $claim, array $data = []): Claim
    {
        abort_unless($claim->status === 'approved', 422, 'Only approved claims can be paid.');

        // Block payment if receipts are required but missing
        $claim->loadMissing('category');
        if ($claim->category?->requires_receipt && $claim->category?->is_travel) {
            $missingTrips = $claim->travelTrips()->where('receipt_uploaded', false)
                ->get()->map(fn($t) => $t->trip_date->format('d M Y'))->all();
            if (count($missingTrips) > 0) {
                abort(422, 'Cannot process payment: trip documents missing for — ' . implode(', ', $missingTrips) . '.');
            }
        } elseif ($claim->category?->requires_receipt) {
            $missing = $claim->items()->where('receipt_uploaded', false)->pluck('description')->all();
            if (count($missing) > 0) {
                abort(422, 'Cannot process payment: supporting documents missing for — ' . implode(', ', $missing) . '.');
            }
        }

        try {
            $this->policyGuard->forClaimPaid($claim->load('category'), Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('ClaimsPolicyGuard::forClaimPaid failed silently', ['error' => $e->getMessage()]);
        }

        $claim->update([
            'status'  => 'paid',
            'paid_at' => now(),
            'paid_by' => $data['paid_by'] ?? Auth::id(),
            'notes'   => $data['notes'] ?? $claim->notes,
        ]);

        activity('claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('paid')
            ->log("Claim {$claim->reference_number} marked as paid.");

        $employee = User::find($claim->employee_id);
        if ($employee) {
            $this->safeNotify($employee, new ClaimEventNotification(
                event:   'paid',
                title:   'Claim Payment Processed',
                message: "Your claim {$claim->reference_number} has been paid.",
                url:     "/claims/my/{$claim->uuid}",
            ));
        }

        return $claim->fresh();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveFirstApprover(Claim $claim): ?\App\Core\User\Models\User
    {
        $steps = $this->getApplicableSteps($claim);

        if ($steps->isEmpty()) {
            return $this->findLineManager($claim->employee_id);
        }

        return $this->resolveApprover($steps->first(), $claim->employee_id);
    }

    private function resolveNextApprover(Claim $claim): ?\App\Core\User\Models\User
    {
        $steps = $this->getApplicableSteps($claim);

        if ($steps->isEmpty()) {
            return null;
        }

        // approval_level is the step index (1-based) that was just completed.
        // The next step sits at that same index in the 0-indexed collection.
        $nextStep = $steps->get($claim->approval_level);

        return $nextStep ? $this->resolveApprover($nextStep, $claim->employee_id) : null;
    }

    private function getApplicableSteps(Claim $claim): \Illuminate\Support\Collection
    {
        $claim->loadMissing('category');
        $isTravel = $claim->category?->is_travel ?? false;
        $amount   = (float) $claim->total_amount;

        return ClaimApprovalStep::where('is_active', true)
            ->where($isTravel ? 'applies_to_travel' : 'applies_to_standard', true)
            ->where(fn($q) => $q->whereNull('amount_min')->orWhere('amount_min', '<=', $amount))
            ->where(fn($q) => $q->whereNull('amount_max')->orWhere('amount_max', '>=', $amount))
            ->orderBy('step_number')
            ->get();
    }

    private function resolveApprover(ClaimApprovalStep $step, int $employeeUserId): ?\App\Core\User\Models\User
    {
        $approver = match ($step->approver_type) {
            'line_manager' => $this->findLineManager($employeeUserId),
            'role'         => $step->role_name
                ? \App\Core\User\Models\User::role([$step->role_name])
                    ->where('id', '!=', $employeeUserId)
                    ->first()
                : null,
            'employee'     => $step->employee_id
                ? \App\Core\User\Models\User::find($step->employee_id)
                : null,
            default        => null,
        };

        if (! $approver) {
            return null;
        }

        // Honour any active delegation: if the resolved approver has delegated
        // their authority to someone else, route to the delegate instead.
        $effectiveId = $this->delegationService->resolveApprover($approver->id);

        return $effectiveId !== $approver->id
            ? \App\Core\User\Models\User::find($effectiveId)
            : $approver;
    }

    private function findLineManager(int $userId): ?\App\Core\User\Models\User
    {
        $managerId = DB::table('hr_people')
            ->join('hr_people as emp', 'emp.manager_id', '=', 'hr_people.id')
            ->where('emp.user_id', $userId)
            ->value('hr_people.user_id');

        if (! $managerId) {
            return null;
        }

        // Honour active delegation for the manager as well
        $effectiveId = $this->delegationService->resolveApprover($managerId);

        return \App\Core\User\Models\User::find($effectiveId);
    }
}
