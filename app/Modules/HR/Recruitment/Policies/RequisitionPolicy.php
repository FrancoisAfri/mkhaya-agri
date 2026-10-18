<?php

namespace App\Modules\HR\Recruitment\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;

class RequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view_recruitment', 'manage_recruitment']);
    }

    public function view(User $user, Requisition $requisition): bool
    {
        return $user->hasAnyPermission(['view_recruitment', 'manage_recruitment']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_recruitment_requisition');
    }

    public function update(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('manage_recruitment')
            && $requisition->status === RequisitionStatus::Draft;
    }

    public function approve(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('approve_recruitment_requisition');
    }

    public function reject(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('approve_recruitment_requisition');
    }

    public function delete(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('manage_recruitment')
            && in_array($requisition->status, [
                RequisitionStatus::Draft,
                RequisitionStatus::Cancelled,
            ]);
    }

    public function submit(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('create_recruitment_requisition')
            || $user->id === $requisition->requested_by;
    }

    public function convert(User $user, Requisition $requisition): bool
    {
        return $user->hasPermissionTo('manage_recruitment_vacancy')
            && $requisition->status->canConvert();
    }
}
