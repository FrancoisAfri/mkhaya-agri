<?php

namespace App\Modules\HR\Recruitment\Events;

use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequisitionApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Requisition $requisition) {}
}
