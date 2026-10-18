<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Who may read, draft, approve and retire a bill of materials.
 *
 * `update` answers false for anything that is not a draft, whatever permissions the user holds.
 * The database refuses the write anyway — the MFG-002 triggers are the real guarantee — but a
 * policy that said yes would put an Edit button on a screen where saving raises a database
 * exception, and a user cannot tell that apart from a broken system.
 */
class BomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_BOMS);
    }

    public function view(User $user, Bom $bom): bool
    {
        return $user->can(RoleCatalog::VIEW_BOMS);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_BOMS);
    }

    public function update(User $user, Bom $bom): bool
    {
        return $bom->isEditable() && $user->can(RoleCatalog::MANAGE_BOMS);
    }

    /** Deleting a draft is fine; deleting history is not, whoever asks. */
    public function delete(User $user, Bom $bom): bool
    {
        return $bom->status === Bom::DRAFT && $user->can(RoleCatalog::MANAGE_BOMS);
    }

    /**
     * Revising is how an approved BOM is changed, so it is allowed precisely where editing is
     * not: on a revision that has already been approved.
     */
    public function revise(User $user, Bom $bom): bool
    {
        return in_array($bom->status, [Bom::APPROVED, Bom::SUPERSEDED], true)
            && $user->can(RoleCatalog::MANAGE_BOMS);
    }

    /**
     * Approving needs its own permission AND a draft to approve.
     *
     * Self-approval is refused in BomVersioning rather than here: this answers "may you approve
     * BOMs", and the service answers "may you approve *this* one", which depends on who drafted
     * it and on the factory's policy.
     */
    public function approve(User $user, Bom $bom): bool
    {
        return $bom->status === Bom::DRAFT && $user->can(RoleCatalog::APPROVE_BOMS);
    }

    public function obsolete(User $user, Bom $bom): bool
    {
        return $bom->status !== Bom::OBSOLETE && $user->can(RoleCatalog::OBSOLETE_BOMS);
    }
}
