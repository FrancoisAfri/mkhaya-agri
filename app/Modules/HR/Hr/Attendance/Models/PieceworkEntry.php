<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Work measured by output, by a worker or a crew (shared out), approved at the rate in force. */
class PieceworkEntry extends SitedModel
{
    public const ALLOCATIONS = ['individual', 'equal', 'percentage', 'recorded'];

    protected $table = 'attendance_piecework_entries';

    protected $casts = ['work_date' => 'date', 'measured_quantity' => 'float', 'approved_quantity' => 'float', 'rejected_quantity' => 'float',
        'rework_quantity' => 'float', 'rate' => 'float', 'amount' => 'float', 'reviewed_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];

    public function shares(): HasMany
    {
        return $this->hasMany(PieceworkShare::class, 'entry_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id');
    }
}
