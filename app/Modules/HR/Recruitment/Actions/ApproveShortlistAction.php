<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveShortlistAction
{
    use PolicyValidationTrait;

    public function execute(Shortlist $shortlist, int $approverId): Shortlist
    {
        // Self-approval: the person who generated the shortlist cannot approve it
        if ($approverId === $shortlist->generated_by) {
            throw ValidationException::withMessages([
                'approver_id' => 'You cannot approve a shortlist you generated.',
            ]);
        }

        if ($shortlist->status !== ShortlistStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => "Shortlist is not pending approval (current: {$shortlist->status->label()}).",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'shortlist.approved',
            payload: [
                'shortlist_id' => $shortlist->id,
                'job_id'       => $shortlist->job_id,
                'approver_id'  => $approverId,
                'item_count'   => $shortlist->items()->count(),
            ],
            userId: $approverId,
        );

        return DB::transaction(function () use ($shortlist, $approverId) {
            // status/approved_by/approved_at are guarded on the model so they can
            // never be mass-assigned from HTTP input; this trusted action force-fills.
            $shortlist->forceFill([
                'status'      => ShortlistStatus::Approved->value,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'shortlist.approved',
                subjectId:   $shortlist->id,
                subjectType: Shortlist::class,
                payload:     [
                    'approver_id' => $approverId,
                    'job_id'      => $shortlist->job_id,
                    'item_count'  => $shortlist->items()->count(),
                ],
                userId: $approverId,
            );

            return $shortlist->fresh();
        });
    }
}
