<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinPostingLimit;

/**
 * Resource-level authority for manual journal posting (Phase 0, 0.2). The
 * finance.journals.post permission decides who may post at all; an optional
 * fin_posting_limits row caps the amount and scopes it to a company. System
 * postings (payroll etc.) bypass this — they never touch it.
 */
class PostingAuthorizer
{
    public const PERMISSION = 'finance.journals.post';

    /** Throw a PostingException if $user may not post a manual journal of $amount. */
    public function assertCanPost(?User $user, float $amount, ?int $companyId = null): void
    {
        if (! $user || ! $user->can(self::PERMISSION)) {
            throw new PostingException('You are not permitted to post journals.');
        }

        $limit = FinPostingLimit::forUser($user->id, $companyId);
        if (! $limit) {
            return; // permission granted, no explicit cap
        }

        if (! $limit->can_post) {
            throw new PostingException('Journal posting is disabled for your account.');
        }

        if ($limit->max_amount !== null && $amount > (float) $limit->max_amount) {
            throw new PostingException(sprintf(
                'This journal (%s) exceeds your posting limit of %s.',
                number_format($amount, 2), number_format((float) $limit->max_amount, 2)
            ));
        }
    }

    /** UX helper: describe a user's authority for the journal screen. */
    public function describe(?User $user, ?int $companyId = null): array
    {
        $canPost = $user && $user->can(self::PERMISSION);
        $limit   = $user ? FinPostingLimit::forUser($user->id, $companyId) : null;

        return [
            'can_post'  => $canPost && (! $limit || $limit->can_post),
            'max_amount' => $limit?->max_amount !== null ? (float) $limit->max_amount : null,
        ];
    }
}
