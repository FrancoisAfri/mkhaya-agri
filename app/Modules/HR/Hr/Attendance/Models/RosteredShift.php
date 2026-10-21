<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shift on the roster: a time window, perhaps at a site or field or for a job, with the workers assigned to it. */
class RosteredShift extends SitedModel
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const CANCELLED = 'cancelled';

    protected $table = 'attendance_rostered_shifts';

    protected $casts = ['starts_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'ends_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'required_certifications' => 'array', 'paid_break' => 'boolean'];

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'shift_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('status', 'assigned');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'template_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(Sites::model('work_order'), 'work_order_id');
    }

    public function plannedMinutes(): int
    {
        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at) - ($this->paid_break ? 0 : (int) $this->break_minutes));
    }
}
