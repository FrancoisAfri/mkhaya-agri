<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OfferSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Offer $offer) {}
}
