<?php

namespace App\Modules\HR\Leave\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveApplication extends Model
{
    use SoftDeletes;
    use HasFactory, LogsActivity;

    protected $table = 'leave_applications';

    // Because the table uses 'created_at' and 'updated_at' columns (timestamps)
    public $timestamps = true;

    // Define the column names if Laravel's defaults differ (they match here)
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'notes',
        'supporting_docs',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'status',
        'hr_id',
        'leave_type_id',
        'manager_id',
        'reject_reason',
        'leave_days',
        'leave_hours',
        'canceller_id',
        'cancellation_reason',
        'leave_taken',
    ];

    protected $casts = [
        'start_date' => 'integer',
        'end_date' => 'integer',
        'start_time' => 'integer',
        'end_time' => 'integer',
        'status' => 'integer',
        'leave_days' => 'integer',
        'leave_hours' => 'integer',
        'leave_taken' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships (adjust model names as needed)
    public function hr()
    {
        return $this->belongsTo(User::class, 'hr_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'canceller_id');
    }

    // Optional: Accessors to convert Unix timestamps to Carbon instances
    public function getStartDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    public function getEndDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::createFromTimestamp($value) : null;
    }

    // Mutators to store Carbon instances as Unix timestamps
    public function setStartDateAttribute($value)
    {
        $this->attributes['start_date'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    public function setEndDateAttribute($value)
    {
        $this->attributes['end_date'] = $value instanceof \Carbon\Carbon ? $value->timestamp : $value;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveApplication')
            ->logOnly(['status', 'start_date', 'end_date', 'leave_type_id', 'hr_id', 'manager_id', 'reject_reason', 'leave_days'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $statusMap = [0 => 'pending', 1 => 'approved', 2 => 'rejected', 3 => 'cancelled'];
        $statusLabel = $statusMap[$this->status] ?? 'unknown';
        return match ($eventName) {
            'created' => "{$actor} submitted a leave application ({$this->leave_days} days).",
            'updated' => "{$actor} updated leave application #{$this->id} — status: {$statusLabel}.",
            'deleted' => "{$actor} deleted leave application #{$this->id}.",
            default   => "{$actor} triggered \"{$eventName}\" on leave application #{$this->id}.",
        };
    }
}
