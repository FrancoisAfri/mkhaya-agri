<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectShortlistAction
{
    use PolicyValidationTrait;

    public function execute(Shortlist $shortlist, int $rejectorId, string $reason): Shortlist
    {
        if ($shortlist->status !== ShortlistStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => "Shortlist is not pending approval (current: {$shortlist->status->label()}).",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'shortlist.rejected',
            payload: [
                'shortlist_id' => $shortlist->id,
                'job_id'       => $shortlist->job_id,
                'rejector_id'  => $rejectorId,
                'reason'       => $reason,
            ],
            userId: $rejectorId,
        );

        return DB::transaction(function () use ($shortlist, $rejectorId, $reason) {
            // status is guarded on the model (never mass-assignable from HTTP);
            // this trusted action force-fills it alongside the notes.
            $shortlist->forceFill([
                'status' => ShortlistStatus::Rejected->value,
                'notes'  => $reason,
            ])->save();

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'shortlist.rejected',
                subjectId:   $shortlist->id,
                subjectType: Shortlist::class,
                payload:     ['rejector_id' => $rejectorId, 'reason' => $reason],
                userId:      $rejectorId,
            );

            return $shortlist->fresh();
        });
    }
}
