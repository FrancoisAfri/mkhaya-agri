<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderMachine extends FarmOwnedModel
{
    protected $table = 'work_order_machines';

    protected $casts = ['booked_from' => \App\Modules\Agri\Casts\FarmDateTime::class, 'booked_to' => \App\Modules\Agri\Casts\FarmDateTime::class, 'planned_hours' => 'float', 'actual_hours' => 'float', 'cost' => 'float', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FarmWorkOrder::class, 'work_order_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'machine_id');
    }
}
