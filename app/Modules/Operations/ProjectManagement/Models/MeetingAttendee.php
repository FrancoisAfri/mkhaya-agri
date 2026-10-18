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
 * Somebody in the room (§18).
 *
 * `user_id` is nullable on purpose: a client's engineer attends whether or not anybody gave them a
 * login, and a register that could only record account holders would record half the room.
 */
class MeetingAttendee extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_meeting_attendees';

    public const ATTENDANCE = ['invited', 'present', 'apology', 'absent'];

    protected $fillable = [
        'uuid', 'company_id', 'meeting_id', 'user_id', 'name', 'organisation', 'attendance',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }
}
