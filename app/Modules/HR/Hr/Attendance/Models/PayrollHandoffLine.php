<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollHandoffLine extends EngineModel
{
    protected $table = 'attendance_payroll_handoff_lines';

    protected $casts = ['piecework_amount' => 'float', 'delivered_piecework' => 'float'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id')->withoutGlobalScopes();
    }
}
