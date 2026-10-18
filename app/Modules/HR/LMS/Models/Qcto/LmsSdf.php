<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsSdf extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_sdfs';

    protected $fillable = [
        'user_id', 'seta_id', 'registration_number',
        'registered_at', 'expires_at',
        'is_primary', 'department', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'registered_at' => 'date',
        'expires_at'    => 'date',
        'is_primary'    => 'boolean',
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

    public function wspPlans()
    {
        return $this->hasMany(LmsWspPlan::class, 'sdf_id');
    }

    public function atrReports()
    {
        return $this->hasMany(LmsAtrReport::class, 'sdf_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS SDF')
            ->logOnly(['registration_number', 'seta_id', 'is_primary', 'expires_at', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " SDF #{$this->id} (reg: {$this->registration_number}) — primary: " . ($this->is_primary ? 'yes' : 'no') . '.');
    }
}
