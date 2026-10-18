<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class DisciplinaryHearing extends Model
{
    use SoftDeletes;
    protected $table = 'disciplinary_hearings';

    protected $fillable = [
        'case_id', 'chairperson_id', 'initiator_id', 'hr_rep_id',
        'status', 'hearing_type', 'scheduled_at', 'venue', 'venue_type',
        'meeting_link', 'duration_minutes', 'notice_issued', 'notice_issued_date',
        'pack_generated', 'adjournment_reason', 'adjourned_to',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'scheduled_at'       => 'datetime',
        'adjourned_to'       => 'datetime',
        'notice_issued'      => 'boolean',
        'pack_generated'     => 'boolean',
        'notice_issued_date' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->uuid = (string) Str::uuid();
            $model->hearing_number = static::generateNumber();
        });
    }

    private static function generateNumber(): string
    {
        $prefix = 'HRG-' . date('Y') . '-';
        $last   = static::where('hearing_number', 'like', $prefix . '%')
            ->orderByDesc('id')->value('hearing_number');
        $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairperson_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function hrRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_rep_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(DisciplinaryHearingAttendee::class, 'hearing_id');
    }

    public function minutes(): HasMany
    {
        return $this->hasMany(DisciplinaryHearingMinute::class, 'hearing_id')->orderBy('sequence');
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(DisciplinaryHearingOutcome::class, 'hearing_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed'   => 'success',
            'in_progress' => 'primary',
            'adjourned'   => 'warning',
            'cancelled'   => 'error',
            default       => 'grey',
        };
    }
}
