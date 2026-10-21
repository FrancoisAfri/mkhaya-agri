<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A team rostered, clocked and paid piecework together, under a leader. */
class Crew extends SitedModel
{
    protected $table = 'attendance_crews';

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'crew_id');
    }
}
