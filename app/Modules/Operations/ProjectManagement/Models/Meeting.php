<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A meeting, its agenda, its minutes and who was in the room (§18).
 *
 * Attendance is kept because minutes without it prove nothing: "it was agreed at the site meeting"
 * is only an argument if the record says who was there to agree it.
 *
 * Actions are tasks on the project carrying `meeting_id`, not rows in a table of their own. An
 * action that lives only in minutes is one nobody works, and a separate register would mean two
 * lists of things to do that never agree.
 */
class Meeting extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_meetings';

    public const KINDS = ['progress', 'site', 'technical', 'client', 'safety', 'kickoff', 'review', 'other'];

    public const STATUSES = ['scheduled', 'held', 'cancelled'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'title', 'kind', 'starts_at', 'duration_minutes',
        'location', 'meeting_url', 'agenda', 'minutes', 'status', 'minutes_circulated_at',
        'chaired_by', 'created_by',
    ];

    protected $casts = [
        'starts_at'             => 'datetime',
        'duration_minutes'      => 'integer',
        'minutes_circulated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class, 'meeting_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Task::class, 'meeting_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('starts_at', '>=', now());
    }

    /** Held, but the minutes have not gone out. The state where decisions quietly evaporate. */
    public function minutesOutstanding(): bool
    {
        return $this->status === 'held' && $this->minutes_circulated_at === null;
    }
}
