<?php

namespace App\Modules\Agri\Models;

use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderWorker extends FarmOwnedModel
{
    protected $table = 'work_order_workers';

    public function worker(): BelongsTo
    {
        return $this->belongsTo(FarmWorker::class, 'worker_id');
    }
}
