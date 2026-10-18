<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsEisaSchedule extends Model
{
    use LogsActivity;

    protected $table = 'lms_eisa_schedules';

    protected $fillable = [
        'learnership_id', 'aqp_id', 'hr_person_id',
        'scheduled_date', 'venue', 'status',
        'score', 'result', 'certificate_number', 'certificate_issued_at',
        'aqp_reference', 'notes', 'captured_by',
    ];

    protected $casts = [
        'scheduled_date'      => 'date',
        'certificate_issued_at'=> 'date',
        'score'               => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function learnership()
    {
        return $this->belongsTo(LmsLearnership::class, 'learnership_id');
    }

    public function aqp()
    {
        return $this->belongsTo(LmsAqp::class, 'aqp_id');
    }

    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function isCompetent(): bool
    {
        return $this->result === 'competent';
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['scheduled', 'deferred']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS EISA Schedule')
            ->logOnly(['scheduled_date', 'status', 'score', 'result', 'certificate_number', 'aqp_reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " EISA schedule #{$this->id} — status: {$this->status}, result: {$this->result}.");
    }
}
