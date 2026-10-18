<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class PaCompetency extends Model
{
    use LogsActivity, SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'pa_competencies';

    protected $fillable = [
        'uuid', 'name', 'category', 'description', 'max_level', 'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_level' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function anchors(): HasMany
    {
        return $this->hasMany(PaCompetencyAnchor::class, 'competency_id')->orderBy('level');
    }

    public function roleLinks(): HasMany
    {
        return $this->hasMany(PaRoleCompetency::class, 'competency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['name', 'category', 'is_active'])
            ->dontSubmitEmptyLogs();
    }
}
