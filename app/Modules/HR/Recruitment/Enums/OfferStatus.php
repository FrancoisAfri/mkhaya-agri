<?php

namespace App\Modules\HR\Recruitment\Enums;

enum OfferStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Sent            = 'sent';
    case Accepted        = 'accepted';
    case Declined        = 'declined';
    case Expired         = 'expired';
    case Withdrawn       = 'withdrawn';

    public function label(): string
    {
        return match($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Sent            => 'Sent',
            self::Accepted        => 'Accepted',
            self::Declined        => 'Declined',
            self::Expired         => 'Expired',
            self::Withdrawn       => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft           => 'default',
            self::PendingApproval => 'warning',
            self::Approved        => 'info',
            self::Sent            => 'primary',
            self::Accepted        => 'success',
            self::Declined        => 'error',
            self::Expired         => 'default',
            self::Withdrawn       => 'secondary',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Declined, self::Expired, self::Withdrawn]);
    }

    public function allowedTransitions(): array
    {
        return match($this) {
            self::Draft           => [self::PendingApproval, self::Withdrawn],
            self::PendingApproval => [self::Approved, self::Withdrawn],
            self::Approved        => [self::Sent, self::Withdrawn],
            self::Sent            => [self::Accepted, self::Declined, self::Expired, self::Withdrawn],
            self::Accepted, self::Declined, self::Expired, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions());
    }
}
