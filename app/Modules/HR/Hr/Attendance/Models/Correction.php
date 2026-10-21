<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A requested change to a clock time, reviewed by someone else; the original event is kept and superseded. */
class Correction extends SitedModel
{
    protected $table = 'attendance_corrections';

    protected $casts = ['original_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'corrected_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'reviewed_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class, 'timesheet_id');
    }
}
