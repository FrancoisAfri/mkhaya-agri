<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Contracts\Models\Contract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CandidateConverted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Contract $contract) {}
}
