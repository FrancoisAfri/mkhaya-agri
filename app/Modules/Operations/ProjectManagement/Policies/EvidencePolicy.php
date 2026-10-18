<?php

namespace App\Modules\Operations\ProjectManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;

/**
 * Authorisation for the Evidence Ledger.
 *
 * The abilities are shaped by what the ledger actually permits (PM-ADR-002): you may *record* and
 * you may *supersede*. There is no update ability and no delete ability, because those operations
 * do not exist — the database rejects them. A policy that pretended otherwise would be describing a
 * system we do not have.
 *
 * `override` is the heaviest grant in the module: it lets someone state a progress or cost figure
 * that the evidence does not support. It is separated from everything else so it can be given to
 * very few people and audited closely.
 */
class EvidencePolicy
{
    public function __construct(private readonly PermissionEvaluator $evaluator)
    {
    }

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin || $user->is_master_admin) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->evaluator->canAnywhere($user, RoleCatalog::VIEW_EVIDENCE);
    }

    public function view(User $user, EvidenceEvent $event): bool
    {
        return $this->evaluator->can($user, RoleCatalog::VIEW_EVIDENCE, $event->project);
    }

    /** Recording what happened — the field worker's core grant. */
    public function record(User $user): bool
    {
        return $this->evaluator->canAnywhere($user, RoleCatalog::RECORD_EVIDENCE);
    }

    /** Correcting an earlier observation by writing a superseding event. */
    public function supersede(User $user, EvidenceEvent $event): bool
    {
        return $this->evaluator->can($user, RoleCatalog::SUPERSEDE_EVIDENCE, $event->project);
    }

    /** Overriding a derived number with a stated one. Requires a reason; always audited. */
    public function override(User $user): bool
    {
        return $this->evaluator->can($user, RoleCatalog::OVERRIDE_DERIVED);
    }

    /** Running the hash-chain audit. */
    public function verify(User $user): bool
    {
        return $this->evaluator->can($user, RoleCatalog::VERIFY_EVIDENCE);
    }
}
