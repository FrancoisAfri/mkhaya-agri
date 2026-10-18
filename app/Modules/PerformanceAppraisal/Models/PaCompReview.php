<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCompReview extends Model
{
    use LogsActivity;

    protected $table = 'pa_comp_reviews';

    protected $fillable = [
        'uuid', 'cycle_id', 'name', 'status', 'merit_budget_pct', 'bonus_budget_pct',
        'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'merit_budget_pct' => 'float',
        'bonus_budget_pct' => 'float',
        'approved_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(PaCompRecommendation::class, 'comp_review_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['name', 'status', 'merit_budget_pct', 'bonus_budget_pct', 'approved_by'])
            ->dontSubmitEmptyLogs();
    }
}
