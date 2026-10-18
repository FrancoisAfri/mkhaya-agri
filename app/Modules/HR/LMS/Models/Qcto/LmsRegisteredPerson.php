<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsRegisteredPerson extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_registered_persons';

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'id_number', 'email',
        'person_type', 'registration_number', 'seta_id',
        'registered_at', 'expires_at',
        'nqf_levels', 'fields_of_study',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'registered_at'   => 'date',
        'expires_at'      => 'date',
        'nqf_levels'      => 'array',
        'fields_of_study' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isAssessor(): bool
    {
        return in_array($this->person_type, ['assessor', 'assessor_moderator']);
    }

    public function isModerator(): bool
    {
        return in_array($this->person_type, ['moderator', 'assessor_moderator']);
    }

    public function scopeAssessors($query)
    {
        return $query->whereIn('person_type', ['assessor', 'assessor_moderator']);
    }

    public function scopeModerators($query)
    {
        return $query->whereIn('person_type', ['moderator', 'assessor_moderator']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Registered Person')
            ->logOnly(['first_name', 'last_name', 'person_type', 'registration_number', 'seta_id', 'expires_at', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " registered person \"{$this->first_name} {$this->last_name}\" ({$this->person_type}).");
    }
}
