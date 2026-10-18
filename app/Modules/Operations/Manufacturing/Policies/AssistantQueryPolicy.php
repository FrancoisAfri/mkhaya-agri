<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * The manufacturing assistant (MFG-019). Asking is its own grant; each answer is further limited
 * by the permission its figures need, which the assistant checks per question.
 */
class AssistantQueryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::USE_ASSISTANT);
    }

    public function recommendations(User $user): bool
    {
        return $user->can(RoleCatalog::USE_ASSISTANT);
    }

    public function act(User $user): bool
    {
        return $user->can(RoleCatalog::ACT_ON_ASSISTANT);
    }
}
