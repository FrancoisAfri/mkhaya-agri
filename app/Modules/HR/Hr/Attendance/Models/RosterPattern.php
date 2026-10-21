<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rotating cycle kept for reuse — a season's or a production cycle's roster. */
class RosterPattern extends SitedModel
{
    protected $table = 'attendance_roster_patterns';

    protected $casts = ['cycle' => 'array', 'workers' => 'array', 'is_active' => 'boolean'];

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id');
    }
}
