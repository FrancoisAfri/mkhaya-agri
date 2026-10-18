<?php

namespace App\Modules\HR\Recruitment\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Candidate;

class CandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.view');
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.create');
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.edit');
    }

    public function uploadCv(User $user, Candidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.upload_cv');
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        if (!$user->hasPermissionTo('recruitment.candidates.delete')) {
            return false;
        }

        // Cannot delete a candidate with any active applications
        return $candidate->applications()->active()->doesntExist();
    }

    public function anonymise(User $user, Candidate $candidate): bool
    {
        // POPIA erasure — restricted to HR admin only
        return $user->hasPermissionTo('recruitment.candidates.anonymise');
    }
}
