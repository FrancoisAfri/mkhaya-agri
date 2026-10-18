<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Recruitment\Models\Application;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CandidateApplied
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Application $application) {}
}
