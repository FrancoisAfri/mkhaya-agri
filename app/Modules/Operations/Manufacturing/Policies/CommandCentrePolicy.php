<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Incident;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * The Command & Control Centre: watching, responding to incidents, asking equipment to act and
 * approving it. Requesting and approving a command are separate grants, and the service also
 * refuses a requester approving their own.
 */
class CommandCentrePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COMMAND) || $user->can(RoleCatalog::RESPOND_INCIDENTS);
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->viewAny($user);
    }

    public function respond(User $user): bool
    {
        return $user->can(RoleCatalog::RESPOND_INCIDENTS);
    }

    public function command(User $user): bool
    {
        return $user->can(RoleCatalog::REQUEST_COMMANDS);
    }

    public function approveCommand(User $user): bool
    {
        return $user->can(RoleCatalog::APPROVE_COMMANDS);
    }

    /** Devices, thresholds and escalation policies. */
    public function configure(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_AGRICULTURE);
    }
}
