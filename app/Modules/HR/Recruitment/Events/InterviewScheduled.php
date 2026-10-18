<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Recruitment\Models\Interview;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InterviewScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Interview $interview) {}
}
