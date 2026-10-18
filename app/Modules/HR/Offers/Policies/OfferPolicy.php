<?php

namespace App\Modules\HR\Offers\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;

class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('offers.view');
    }

    public function view(User $user, Offer $offer): bool
    {
        return $user->hasPermissionTo('offers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('offers.create');
    }

    public function send(User $user, Offer $offer): bool
    {
        if (!$user->hasPermissionTo('offers.send')) {
            return false;
        }

        return $offer->status->canTransitionTo(OfferStatus::Sent);
    }

    public function withdraw(User $user, Offer $offer): bool
    {
        if (!$user->hasPermissionTo('offers.withdraw')) {
            return false;
        }

        return !$offer->status->isTerminal();
    }
}
