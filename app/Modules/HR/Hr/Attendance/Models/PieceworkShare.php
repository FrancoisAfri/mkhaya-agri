<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceworkShare extends SitedModel
{
    protected $table = 'attendance_piecework_shares';

    protected $casts = ['percent' => 'float', 'quantity' => 'float', 'amount' => 'float'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }
}
