<?php

namespace App\Modules\Agri\Models;


class WorkOrderLog extends FarmOwnedModel
{
    protected $table = 'work_order_logs';

    protected $hidden = ['photo_data'];

    protected $casts = ['quantity' => 'float', 'machine_hours' => 'float', 'logged_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'resolved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function workOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FarmWorkOrder::class, 'work_order_id');
    }

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['photo_data', 'updated_at', 'created_at']);
    }
}
