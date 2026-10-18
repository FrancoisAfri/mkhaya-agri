<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShortlistGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shortlist $shortlist) {}
}
